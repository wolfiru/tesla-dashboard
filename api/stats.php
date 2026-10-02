<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/sicherheit.php';
td_api_schuetzen();

header('Content-Type: application/json; charset=utf-8');

$range = isset($_GET['range']) ? $_GET['range'] : '30d';

$intervalMap = [
    '24h' => '24 hours',
    '7d'  => '7 days',
    '30d' => '30 days',
    '1y'  => '1 year',
    'all' => '100 years',
];

$interval = isset($intervalMap[$range]) ? $intervalMap[$range] : '30 days';
$since    = "NOW() - INTERVAL '$interval'";

$connstr = sprintf(
    "host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5",
    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
);
$db = @pg_connect($connstr);
if (!$db) {
    echo json_encode(['ok' => false, 'error' => 'Datenbankverbindung fehlgeschlagen']);
    exit;
}

function fetchOne($db, $sql, $params = []) {
    $res = pg_query_params($db, $sql, $params);
    if (!$res || pg_num_rows($res) === 0) return null;
    return pg_fetch_assoc($res);
}

function fetchAll($db, $sql, $params = []) {
    $res = pg_query_params($db, $sql, $params);
    if (!$res) return [];
    $rows = [];
    while ($row = pg_fetch_assoc($res)) $rows[] = $row;
    return $rows;
}

// Fahrten-Übersicht
$driveSummary = fetchOne($db, "
    SELECT COUNT(*)                              AS total_drives,
           COALESCE(SUM(distance), 0)            AS total_km,
           COALESCE(AVG(outside_temp_avg), 0)    AS avg_temp,
           COALESCE(MAX(speed_max), 0)           AS max_speed,
           (SELECT COALESCE(SUM(f.kwh), 0) FROM auswertung.fahrt f WHERE f.start_date >= $since) AS total_kwh
    FROM drives
    WHERE car_id = 1 AND start_date >= $since
", []);

// Letzte 20 Fahrten
$recentDrives = fetchAll($db, "
    SELECT d.id,
           d.start_date AT TIME ZONE 'UTC' AS start_local,
           d.end_date   AT TIME ZONE 'UTC' AS end_local,
           d.distance,
           COALESCE(d.duration_min,
               EXTRACT(EPOCH FROM (d.end_date - d.start_date)) / 60
           ) AS duration_min,
           d.outside_temp_avg,
           d.speed_max,
           p_s.battery_level AS start_battery_level,
           p_e.battery_level AS end_battery_level,
           d.start_rated_range_km,
           d.end_rated_range_km,
           a1.city AS start_city,
           a2.city AS end_city,
           f.kwh AS kwh
    FROM drives d
    LEFT JOIN auswertung.fahrt f ON f.drive_id = d.id
    LEFT JOIN positions p_s ON p_s.id = d.start_position_id
    LEFT JOIN positions p_e ON p_e.id = d.end_position_id
    LEFT JOIN addresses a1 ON a1.id = d.start_address_id
    LEFT JOIN addresses a2 ON a2.id = d.end_address_id
    WHERE d.car_id = 1 AND d.start_date >= $since
    ORDER BY d.start_date DESC
    LIMIT 20
", []);

// Alle Fahrten (Monatstabelle im Tab Fahrten – unabhängig vom Zeitraum)
$alleDrives = fetchAll($db, "
    SELECT d.id,
           d.start_date AT TIME ZONE 'UTC' AS start_local,
           d.end_date   AT TIME ZONE 'UTC' AS end_local,
           d.distance,
           COALESCE(d.duration_min,
               EXTRACT(EPOCH FROM (d.end_date - d.start_date)) / 60
           ) AS duration_min,
           d.outside_temp_avg,
           d.speed_max,
           p_s.battery_level AS start_battery_level,
           p_e.battery_level AS end_battery_level,
           d.start_rated_range_km,
           d.end_rated_range_km,
           a1.city AS start_city,
           a2.city AS end_city,
           f.kwh AS kwh
    FROM drives d
    LEFT JOIN auswertung.fahrt f ON f.drive_id = d.id
    LEFT JOIN positions p_s ON p_s.id = d.start_position_id
    LEFT JOIN positions p_e ON p_e.id = d.end_position_id
    LEFT JOIN addresses a1 ON a1.id = d.start_address_id
    LEFT JOIN addresses a2 ON a2.id = d.end_address_id
    WHERE d.car_id = 1
    ORDER BY d.start_date DESC
    LIMIT 5000
", []);

// Monatliche Fahrstatistik
$monthlyStats = fetchAll($db, "
    SELECT TO_CHAR(start_date AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Vienna', 'YYYY-MM') AS month,
           COUNT(*)                              AS drives,
           COALESCE(SUM(distance), 0)            AS km,
           COALESCE(AVG(outside_temp_avg), 0)    AS avg_temp,
           COALESCE(AVG(speed_max), 0)           AS avg_max_speed
    FROM drives
    WHERE car_id = 1 AND start_date >= $since
    GROUP BY month
    ORDER BY month DESC
", []);

// Ladung daheim? (Adresse im Umkreis der Wallbox; Näherung in Metern, reicht für 250 m)
$daheimSql = sprintf("(SELECT sqrt(power((ad.latitude - %F) * 111320, 2) + power((ad.longitude - %F) * 111320 * cos(radians(%F)), 2)) < %d
                        FROM addresses ad WHERE ad.id = %%s)", HEIM_LAT, HEIM_LON, HEIM_LAT, HEIM_RADIUS_M);

// Mischpreis für den Verbrauch unterwegs: je nachdem, wo der Strom der letzten 12 Monate geladen wurde
$mix = fetchOne($db, "
    SELECT COALESCE(SUM(charge_energy_used) FILTER (WHERE " . sprintf($daheimSql, 'address_id') . "), 0) AS daheim,
           COALESCE(SUM(charge_energy_used), 0) AS gesamt
    FROM charging_processes
    WHERE car_id = 1 AND end_date IS NOT NULL AND start_date >= NOW() - INTERVAL '1 year'
", []);
$mixGesamt = $mix ? (float)$mix['gesamt'] : 0;
$anteilDaheim = $mixGesamt > 0 ? (float)$mix['daheim'] / $mixGesamt : 1.0;
$preisFahrt = round($anteilDaheim * STROMPREIS_CT + (1 - $anteilDaheim) * STROMPREIS_FREMD_CT, 2);

// Lade-Übersicht
$chargeSummary = fetchOne($db, "
    SELECT COUNT(*)                                      AS sessions,
           COALESCE(SUM(charge_energy_used), 0)          AS total_kwh_ac,
           COALESCE(SUM(charge_energy_added), 0)         AS total_kwh_dc,
           -- energiegewichtet, ohne Kurz-Ladungen unter 1 kWh
           COALESCE(SUM(charge_energy_added) FILTER (WHERE charge_energy_added >= 1 AND charge_energy_used > 0)
               / NULLIF(SUM(charge_energy_used) FILTER (WHERE charge_energy_added >= 1 AND charge_energy_used > 0), 0) * 100, 0) AS avg_eff_pct,
           COALESCE(SUM(charge_energy_used) FILTER (WHERE " . sprintf($daheimSql, 'address_id') . "), 0) AS kwh_daheim,
           COALESCE(SUM(charge_energy_used) FILTER (WHERE NOT COALESCE(" . sprintf($daheimSql, 'address_id') . ", false)), 0) AS kwh_fremd,
           COALESCE(SUM(duration_min), 0) AS total_min
    FROM charging_processes
    WHERE car_id = 1 AND start_date >= $since
", []);

// Letzte 10 Ladesessions
$recentCharges = fetchAll($db, "
    SELECT cp.id,
           cp.start_date AT TIME ZONE 'UTC' AS start_local,
           cp.end_date   AT TIME ZONE 'UTC' AS end_local,
           cp.charge_energy_added,
           cp.charge_energy_used,
           cp.duration_min,
           cp.start_battery_level,
           cp.end_battery_level,
           cp.outside_temp_avg,
           CASE WHEN cp.charge_energy_used > 0
                THEN ROUND((cp.charge_energy_added / cp.charge_energy_used)::numeric * 100, 1)
                ELSE NULL END AS efficiency_pct,
           COALESCE(g.name, a.city, 'Unbekannt') AS location,
           COALESCE(" . sprintf($daheimSql, 'cp.address_id') . ", false) AS daheim
    FROM charging_processes cp
    LEFT JOIN geofences g ON g.id = cp.geofence_id
    LEFT JOIN addresses a ON a.id = cp.address_id
    WHERE cp.car_id = 1 AND cp.start_date >= $since
    ORDER BY cp.start_date DESC
    LIMIT 10
", []);

// Alle Ladungen (für die Monatstabelle im Tab Laden – unabhängig vom gewählten Zeitraum)
$alleCharges = fetchAll($db, "
    SELECT cp.id,
           cp.start_date AT TIME ZONE 'UTC' AS start_local,
           cp.end_date   AT TIME ZONE 'UTC' AS end_local,
           cp.charge_energy_added,
           cp.charge_energy_used,
           cp.duration_min,
           cp.start_battery_level,
           cp.end_battery_level,
           cp.outside_temp_avg,
           CASE WHEN cp.charge_energy_used > 0
                THEN ROUND((cp.charge_energy_added / cp.charge_energy_used)::numeric * 100, 1)
                ELSE NULL END AS efficiency_pct,
           COALESCE(g.name, a.city, 'Unbekannt') AS location,
           COALESCE(" . sprintf($daheimSql, 'cp.address_id') . ", false) AS daheim
    FROM charging_processes cp
    LEFT JOIN geofences g ON g.id = cp.geofence_id
    LEFT JOIN addresses a ON a.id = cp.address_id
    WHERE cp.car_id = 1
    ORDER BY cp.start_date DESC
    LIMIT 2000
", []);

// Energie pro Wochentag (Ladeverhalten)
$chargeByWeekday = fetchAll($db, "
    SELECT TO_CHAR(start_date AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Vienna', 'ID') AS dow_iso,
           TO_CHAR(start_date AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Vienna', 'Day') AS dow_name,
           COUNT(*) AS sessions,
           COALESCE(SUM(charge_energy_added), 0) AS total_kwh
    FROM charging_processes
    WHERE car_id = 1 AND start_date >= $since
    GROUP BY dow_iso, dow_name
    ORDER BY dow_iso
", []);

pg_close($db);

// Normalize drives
$drives = array_map(function($d) {
    return [
        'id'           => (int)$d['id'],
        'start_local'  => $d['start_local'],
        'end_local'    => $d['end_local'],
        'distance'     => $d['distance'] !== null ? round((float)$d['distance'], 1) : null,
        'duration_min' => $d['duration_min'] !== null ? round((float)$d['duration_min']) : null,
        'outside_temp' => $d['outside_temp_avg'] !== null ? round((float)$d['outside_temp_avg'], 1) : null,
        'speed_max'    => $d['speed_max'] !== null ? round((float)$d['speed_max']) : null,
        'soc_start'    => $d['start_battery_level'] !== null ? (int)$d['start_battery_level'] : null,
        'soc_end'      => $d['end_battery_level'] !== null ? (int)$d['end_battery_level'] : null,
        'range_start'  => $d['start_rated_range_km'] !== null ? round((float)$d['start_rated_range_km'], 1) : null,
        'range_end'    => $d['end_rated_range_km'] !== null ? round((float)$d['end_rated_range_km'], 1) : null,
        'start_city'   => $d['start_city'],
        'end_city'     => $d['end_city'],
        'kwh'          => $d['kwh'] !== null ? round((float)$d['kwh'], 2) : null,
    ];
}, $recentDrives);
$drivesAlle = array_map(function($d) {
    return [
        'id'           => (int)$d['id'],
        'start_local'  => $d['start_local'],
        'end_local'    => $d['end_local'],
        'distance'     => $d['distance'] !== null ? round((float)$d['distance'], 1) : null,
        'duration_min' => $d['duration_min'] !== null ? round((float)$d['duration_min']) : null,
        'outside_temp' => $d['outside_temp_avg'] !== null ? round((float)$d['outside_temp_avg'], 1) : null,
        'speed_max'    => $d['speed_max'] !== null ? round((float)$d['speed_max']) : null,
        'soc_start'    => $d['start_battery_level'] !== null ? (int)$d['start_battery_level'] : null,
        'soc_end'      => $d['end_battery_level'] !== null ? (int)$d['end_battery_level'] : null,
        'range_start'  => $d['start_rated_range_km'] !== null ? round((float)$d['start_rated_range_km'], 1) : null,
        'range_end'    => $d['end_rated_range_km'] !== null ? round((float)$d['end_rated_range_km'], 1) : null,
        'start_city'   => $d['start_city'],
        'end_city'     => $d['end_city'],
        'kwh'          => $d['kwh'] !== null ? round((float)$d['kwh'], 2) : null,
    ];
}, $alleDrives);

// Normalize charges
$charges = array_map(function($c) {
    return [
        'id'             => (int)$c['id'],
        'start_local'    => $c['start_local'],
        'end_local'      => $c['end_local'],
        'kwh_added'      => $c['charge_energy_added'] !== null ? round((float)$c['charge_energy_added'], 2) : null,
        'kwh_used'       => $c['charge_energy_used'] !== null ? round((float)$c['charge_energy_used'], 2) : null,
        'duration_min'   => $c['duration_min'] !== null ? round((float)$c['duration_min']) : null,
        'soc_start'      => $c['start_battery_level'] !== null ? (int)$c['start_battery_level'] : null,
        'soc_end'        => $c['end_battery_level'] !== null ? (int)$c['end_battery_level'] : null,
        'outside_temp'   => $c['outside_temp_avg'] !== null ? round((float)$c['outside_temp_avg'], 1) : null,
        'efficiency_pct' => $c['efficiency_pct'] !== null ? (float)$c['efficiency_pct'] : null,
        'location'       => $c['location'],
        'daheim'         => $c['daheim'] === 't',
    ];
}, $recentCharges);
// dieselbe Aufbereitung für alle Ladungen
$chargesAlle = array_map(function($c) {
    return [
        'id'             => (int)$c['id'],
        'start_local'    => $c['start_local'],
        'end_local'      => $c['end_local'],
        'kwh_added'      => $c['charge_energy_added'] !== null ? round((float)$c['charge_energy_added'], 2) : null,
        'kwh_used'       => $c['charge_energy_used'] !== null ? round((float)$c['charge_energy_used'], 2) : null,
        'duration_min'   => $c['duration_min'] !== null ? round((float)$c['duration_min']) : null,
        'soc_start'      => $c['start_battery_level'] !== null ? (int)$c['start_battery_level'] : null,
        'soc_end'        => $c['end_battery_level'] !== null ? (int)$c['end_battery_level'] : null,
        'outside_temp'   => $c['outside_temp_avg'] !== null ? round((float)$c['outside_temp_avg'], 1) : null,
        'efficiency_pct' => $c['efficiency_pct'] !== null ? (float)$c['efficiency_pct'] : null,
        'location'       => $c['location'],
        'daheim'         => $c['daheim'] === 't',
    ];
}, $alleCharges);

echo json_encode([
    'ok'    => true,
    'strompreis_ct' => STROMPREIS_CT,
    'strompreis_fremd_ct' => STROMPREIS_FREMD_CT,
    'strompreis_fahrt_ct' => $preisFahrt,          // Mischpreis für Verbrauch (Fahrten)
    'anteil_daheim_pct' => round($anteilDaheim * 100),
    'range' => $range,
    'drives' => [
        'summary' => $driveSummary ? [
            'total_drives' => (int)$driveSummary['total_drives'],
            'total_km'     => round((float)$driveSummary['total_km'], 1),
            'avg_temp'     => round((float)$driveSummary['avg_temp'], 1),
            'max_speed'    => round((float)$driveSummary['max_speed']),
            'total_kwh'    => round((float)$driveSummary['total_kwh'], 2),
        ] : null,
        'recent'  => $drives,
        'alle'    => $drivesAlle,
        'monthly' => array_map(function($m) {
            return [
                'month'         => $m['month'],
                'drives'        => (int)$m['drives'],
                'km'            => round((float)$m['km'], 1),
                'avg_temp'      => round((float)$m['avg_temp'], 1),
                'avg_max_speed' => round((float)$m['avg_max_speed']),
            ];
        }, $monthlyStats),
    ],
    'charging' => [
        'summary' => $chargeSummary ? [
            'sessions'    => (int)$chargeSummary['sessions'],
            'total_kwh_ac'=> round((float)$chargeSummary['total_kwh_ac'], 2),
            'total_kwh_dc'=> round((float)$chargeSummary['total_kwh_dc'], 2),
            'avg_eff_pct' => round((float)$chargeSummary['avg_eff_pct'], 1),
            'kwh_daheim'  => round((float)$chargeSummary['kwh_daheim'], 2),
            'kwh_fremd'   => round((float)$chargeSummary['kwh_fremd'], 2),
            'total_min'   => round((float)$chargeSummary['total_min']),
        ] : null,
        'recent'     => $charges,
        'alle'       => $chargesAlle,
        'by_weekday' => array_map(function($w) {
            return [
                'dow'      => (int)$w['dow_iso'],
                'name'     => trim($w['dow_name']),
                'sessions' => (int)$w['sessions'],
                'kwh'      => round((float)$w['total_kwh'], 2),
            ];
        }, $chargeByWeekday),
    ],
], JSON_UNESCAPED_UNICODE);
