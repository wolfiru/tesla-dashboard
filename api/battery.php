<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/sicherheit.php';
td_api_schuetzen();

header('Content-Type: application/json; charset=utf-8');

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

// Aktuelle Batteriedaten (letzter Eintrag)
$current = fetchOne($db, "
    SELECT p.battery_level,
           p.usable_battery_level,
           p.rated_battery_range_km,
           p.ideal_battery_range_km,
           p.battery_heater,
           p.tpms_pressure_fl,
           p.tpms_pressure_fr,
           p.tpms_pressure_rl,
           p.tpms_pressure_rr,
           p.date AT TIME ZONE 'UTC' AS date_local
    FROM positions p
    WHERE p.car_id = 1
    ORDER BY p.date DESC
    LIMIT 1
", []);

// SOC-Verteilung (Histogramm in 5%-Schritten) – letzte 90 Tage
$socDistribution = fetchAll($db, "
    SELECT (FLOOR(battery_level / 5) * 5) AS soc_bucket,
           COUNT(*) AS count
    FROM positions
    WHERE car_id = 1
      AND battery_level IS NOT NULL
      AND date >= NOW() - INTERVAL '90 days'
    GROUP BY soc_bucket
    ORDER BY soc_bucket
", []);

// Batterie-Degradation: rated_range bei 100% SOC über Zeit (monatlich)
$degradation = fetchAll($db, "
    SELECT TO_CHAR(date AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Vienna', 'YYYY-MM') AS month,
           MAX(rated_battery_range_km) AS max_rated_range,
           AVG(rated_battery_range_km) AS avg_rated_range
    FROM positions
    WHERE car_id = 1
      AND battery_level >= 95
      AND rated_battery_range_km IS NOT NULL
      AND date >= NOW() - INTERVAL '2 years'
    GROUP BY month
    ORDER BY month
", []);

// ── Nutzbare Akkukapazität (Degradation), zwei unabhängige Wege aus Daten des Autos ──
// a) je Tag: Reichweite hochgerechnet auf 100 % × Verbrauchsfaktor des Autos (cars.efficiency) – so rechnet TeslaMate
// b) je Ladung (ab 5 % Akkustand): „Energie hinzugefügt“ ÷ geladene Prozent × 100
const TD_AKKU_NEU_KWH = AKKU_NEU_KWH;   // nutzbar im Neuzustand, config.local.php
$autoEff = fetchOne($db, "SELECT efficiency FROM cars WHERE id = 1");
$autoEff = $autoEff && $autoEff['efficiency'] !== null ? (float)$autoEff['efficiency'] : null;
$kapTage = fetchAll($db, "
    SELECT TO_CHAR(date AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Vienna', 'YYYY-MM-DD') AS tag,
           AVG(rated_battery_range_km / battery_level * 100) AS reichweite100,
           AVG(outside_temp) AS temp,
           COUNT(*) AS n
    FROM positions
    WHERE car_id = 1 AND battery_level >= 50 AND rated_battery_range_km IS NOT NULL
    GROUP BY tag HAVING COUNT(*) >= 3
    ORDER BY tag
", []);
$kapLadungen = fetchAll($db, "
    SELECT TO_CHAR(start_date AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Vienna', 'YYYY-MM-DD') AS tag,
           end_battery_level - start_battery_level AS delta_soc,
           charge_energy_added AS kwh_added
    FROM charging_processes
    WHERE car_id = 1 AND end_date IS NOT NULL AND charge_energy_added > 0
      AND end_battery_level - start_battery_level >= 5
    ORDER BY start_date
", []);
// aktueller Wert: letzte 60 Tage (wie der DaheimLader)
$kapJetzt = fetchOne($db, "
    SELECT AVG(rated_battery_range_km / battery_level * 100) AS reichweite100
    FROM positions
    WHERE car_id = 1 AND battery_level >= 50 AND rated_battery_range_km IS NOT NULL
      AND date > NOW() - INTERVAL '60 days'
", []);

// Ladezyklen pro Monat (Anzahl vollständiger Ladesessions mit > 10 kWh)
$chargeCycles = fetchAll($db, "
    SELECT TO_CHAR(start_date AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Vienna', 'YYYY-MM') AS month,
           COUNT(*) AS sessions,
           COALESCE(SUM(charge_energy_added), 0) AS total_kwh,
           COALESCE(AVG(start_battery_level), 0) AS avg_start_soc,
           COALESCE(AVG(end_battery_level), 0)   AS avg_end_soc
    FROM charging_processes
    WHERE car_id = 1
      AND charge_energy_added > 10
      AND start_date >= NOW() - INTERVAL '1 year'
    GROUP BY month
    ORDER BY month
", []);

// Lade-Effizienz-Trend (letzte 20 Sessions)
$efficiencyTrend = fetchAll($db, "
    SELECT cp.start_date AT TIME ZONE 'UTC' AS start_local,
           cp.charge_energy_added,
           cp.charge_energy_used,
           cp.start_battery_level,
           cp.end_battery_level,
           CASE WHEN cp.charge_energy_used > 0
                THEN ROUND((cp.charge_energy_added / cp.charge_energy_used)::numeric * 100, 1)
                ELSE NULL END AS efficiency_pct
    FROM charging_processes cp
    WHERE cp.car_id = 1
      AND cp.charge_energy_used > 0
      AND cp.charge_energy_added >= 1   -- Kurz-Ladungen (Vorklimatisieren, Standby) verfälschen den Wirkungsgrad
    ORDER BY cp.start_date DESC
    LIMIT 20
", []);

// Temperatur vs. Reichweite Korrelation
$tempRange = fetchAll($db, "
    SELECT FLOOR(d.outside_temp_avg / 5) * 5 AS temp_bucket,
           COUNT(*) AS drives,
           AVG(d.end_rated_range_km - d.start_rated_range_km + d.distance) AS avg_eff_range
    FROM drives d
    WHERE d.car_id = 1
      AND d.outside_temp_avg IS NOT NULL
      AND d.distance > 5
      AND d.start_date >= NOW() - INTERVAL '1 year'
    GROUP BY temp_bucket
    ORDER BY temp_bucket
", []);

pg_close($db);

// Degradation gegenüber der Werksreichweite (config.local.php)
$factory_range = (float)WERKS_REICHWEITE_KM;
$degradation_out = array_map(function($d) use ($factory_range) {
    $max = (float)$d['max_rated_range'];
    return [
        'month'          => $d['month'],
        'max_rated_km'   => round($max, 1),
        'avg_rated_km'   => round((float)$d['avg_rated_range'], 1),
        'degradation_pct'=> $max > 0 ? round((1 - $max / $factory_range) * 100, 2) : null,
    ];
}, $degradation);

$tpmsNominal = TPMS_NOMINAL_BAR;

$summeAdd = array_sum(array_map(fn($l) => (float)$l['kwh_added'], $kapLadungen));
$summeSoc = array_sum(array_map(fn($l) => (int)$l['delta_soc'], $kapLadungen));
$r100Jetzt = $kapJetzt && $kapJetzt['reichweite100'] !== null ? (float)$kapJetzt['reichweite100'] : null;
$kapazitaet = [
    'neu_kwh'        => TD_AKKU_NEU_KWH,
    'auto_eff_kwh_km'=> $autoEff,
    'jetzt_kwh'      => ($r100Jetzt && $autoEff) ? round($r100Jetzt * $autoEff, 1) : null,
    'reichweite100_km' => $r100Jetzt ? round($r100Jetzt) : null,
    'ladungen_kwh'   => $summeSoc > 0 ? round($summeAdd / $summeSoc * 100, 1) : null,
    'ladungen_summe_kwh' => round($summeAdd, 1),
    'ladungen_summe_soc' => $summeSoc,
    'tage' => array_map(fn($t) => [
        'tag' => $t['tag'],
        'kwh' => $autoEff ? round((float)$t['reichweite100'] * $autoEff, 1) : null,
        'reichweite100_km' => round((float)$t['reichweite100']),
        'temp' => $t['temp'] !== null ? round((float)$t['temp'], 1) : null,
    ], $kapTage),
    'ladungen' => array_map(fn($l) => [
        'tag' => $l['tag'],
        'delta_soc' => (int)$l['delta_soc'],
        'kwh_added' => round((float)$l['kwh_added'], 2),
        'kwh' => round((float)$l['kwh_added'] / (int)$l['delta_soc'] * 100, 1),
    ], $kapLadungen),
];

echo json_encode([
    'ok' => true,
    'current' => $current ? [
        'battery_level'         => $current['battery_level'] !== null ? (int)$current['battery_level'] : null,
        'usable_battery_level'  => $current['usable_battery_level'] !== null ? (int)$current['usable_battery_level'] : null,
        'rated_range_km'        => $current['rated_battery_range_km'] !== null ? round((float)$current['rated_battery_range_km'], 1) : null,
        'ideal_range_km'        => $current['ideal_battery_range_km'] !== null ? round((float)$current['ideal_battery_range_km'], 1) : null,
        'battery_heater'        => $current['battery_heater'] === 't',
        'tpms' => [
            'fl'      => $current['tpms_pressure_fl'] !== null ? round((float)$current['tpms_pressure_fl'], 2) : null,
            'fr'      => $current['tpms_pressure_fr'] !== null ? round((float)$current['tpms_pressure_fr'], 2) : null,
            'rl'      => $current['tpms_pressure_rl'] !== null ? round((float)$current['tpms_pressure_rl'], 2) : null,
            'rr'      => $current['tpms_pressure_rr'] !== null ? round((float)$current['tpms_pressure_rr'], 2) : null,
            'nominal' => $tpmsNominal,
        ],
        'date_local' => $current['date_local'],
    ] : null,
    'soc_distribution' => array_map(function($s) {
        return [
            'bucket' => (int)$s['soc_bucket'],
            'count'  => (int)$s['count'],
        ];
    }, $socDistribution),
    'degradation'     => $degradation_out,
    'kapazitaet'      => $kapazitaet,
    'charge_cycles'   => array_map(function($c) {
        return [
            'month'         => $c['month'],
            'sessions'      => (int)$c['sessions'],
            'total_kwh'     => round((float)$c['total_kwh'], 2),
            'avg_start_soc' => round((float)$c['avg_start_soc']),
            'avg_end_soc'   => round((float)$c['avg_end_soc']),
        ];
    }, $chargeCycles),
    'efficiency_trend' => array_map(function($e) {
        return [
            'start_local'    => $e['start_local'],
            'kwh_added'      => $e['charge_energy_added'] !== null ? round((float)$e['charge_energy_added'], 2) : null,
            'kwh_used'       => $e['charge_energy_used'] !== null ? round((float)$e['charge_energy_used'], 2) : null,
            'soc_start'      => $e['start_battery_level'] !== null ? (int)$e['start_battery_level'] : null,
            'soc_end'        => $e['end_battery_level'] !== null ? (int)$e['end_battery_level'] : null,
            'efficiency_pct' => $e['efficiency_pct'] !== null ? (float)$e['efficiency_pct'] : null,
        ];
    }, $efficiencyTrend),
    'temp_range_correlation' => array_map(function($t) {
        return [
            'temp_bucket'    => (int)$t['temp_bucket'],
            'drives'         => (int)$t['drives'],
            'avg_eff_range'  => $t['avg_eff_range'] !== null ? round((float)$t['avg_eff_range'], 1) : null,
        ];
    }, $tempRange),
], JSON_UNESCAPED_UNICODE);
