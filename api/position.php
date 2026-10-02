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

$_query_errors = [];

function fetchOne($db, $sql, $params = []) {
    $res = pg_query_params($db, $sql, $params);
    if (!$res) {
        $GLOBALS['_query_errors'][] = pg_last_error($db);
        return null;
    }
    if (pg_num_rows($res) === 0) return null;
    return pg_fetch_assoc($res);
}

function fetchAll($db, $sql, $params = []) {
    $res = pg_query_params($db, $sql, $params);
    if (!$res) {
        $GLOBALS['_query_errors'][] = pg_last_error($db);
        return [];
    }
    $rows = [];
    while ($row = pg_fetch_assoc($res)) $rows[] = $row;
    return $rows;
}

// Aktuelle Position
$position = fetchOne($db, "
    SELECT p.latitude, p.longitude, p.speed, p.battery_level,
           p.rated_battery_range_km, p.outside_temp, p.inside_temp,
           p.battery_heater, p.drive_id,
           p.tpms_pressure_fl, p.tpms_pressure_fr,
           p.tpms_pressure_rl, p.tpms_pressure_rr,
           p.date AT TIME ZONE 'UTC' AS date_local
    FROM positions p
    WHERE p.car_id = 1
    ORDER BY p.date DESC
    LIMIT 1
", []);

// Aktueller Fahrzeugzustand
$state = fetchOne($db, "
    SELECT state, start_date AT TIME ZONE 'UTC' AS since
    FROM states
    WHERE car_id = 1
    ORDER BY start_date DESC
    LIMIT 1
", []);

// Letzte Adresse (via letzte abgeschlossene Fahrt, da positions kein address_id hat)
$address = fetchOne($db, "
    SELECT a.display_name, a.city, a.road, a.house_number
    FROM addresses a
    JOIN drives d ON d.end_address_id = a.id
    WHERE d.car_id = 1
      AND d.end_date IS NOT NULL
    ORDER BY d.start_date DESC
    LIMIT 1
", []);

// Letzte abgeschlossene Fahrt
$lastDrive = fetchOne($db, "
    SELECT d.distance,
           COALESCE(d.duration_min,
               EXTRACT(EPOCH FROM (d.end_date - d.start_date)) / 60
           ) AS duration_min,
           p_s.battery_level AS start_battery_level,
           p_e.battery_level AS end_battery_level,
           d.start_date AT TIME ZONE 'UTC' AS start_local,
           a1.city AS start_city, a2.city AS end_city
    FROM drives d
    LEFT JOIN positions p_s ON p_s.id = d.start_position_id
    LEFT JOIN positions p_e ON p_e.id = d.end_position_id
    LEFT JOIN addresses a1 ON a1.id = d.start_address_id
    LEFT JOIN addresses a2 ON a2.id = d.end_address_id
    WHERE d.car_id = 1 AND d.end_date IS NOT NULL
    ORDER BY d.start_date DESC
    LIMIT 1
", []);

pg_close($db);

$out = ['ok' => true];

if ($position) {
    $out['position'] = [
        'latitude'            => (float)$position['latitude'],
        'longitude'           => (float)$position['longitude'],
        'speed'               => $position['speed'] !== null ? (float)$position['speed'] : null,
        'battery_level'       => $position['battery_level'] !== null ? (int)$position['battery_level'] : null,
        'rated_battery_range_km' => $position['rated_battery_range_km'] !== null ? round((float)$position['rated_battery_range_km'], 1) : null,
        'outside_temp'        => $position['outside_temp'] !== null ? round((float)$position['outside_temp'], 1) : null,
        'inside_temp'         => $position['inside_temp'] !== null ? round((float)$position['inside_temp'], 1) : null,
        'battery_heater'      => $position['battery_heater'] === 't',
        'drive_id'            => $position['drive_id'],
        'tpms_fl'             => $position['tpms_pressure_fl'] !== null ? round((float)$position['tpms_pressure_fl'], 2) : null,
        'tpms_fr'             => $position['tpms_pressure_fr'] !== null ? round((float)$position['tpms_pressure_fr'], 2) : null,
        'tpms_rl'             => $position['tpms_pressure_rl'] !== null ? round((float)$position['tpms_pressure_rl'], 2) : null,
        'tpms_rr'             => $position['tpms_pressure_rr'] !== null ? round((float)$position['tpms_pressure_rr'], 2) : null,
        'date_local'          => $position['date_local'],
    ];
} else {
    $out['position'] = null;
}

$out['state'] = $state ? [
    'state' => $state['state'],
    'since' => $state['since'],
] : null;

$out['address'] = $address ? [
    'display_name' => $address['display_name'],
    'city'         => $address['city'],
    'road'         => $address['road'],
    'house_number' => $address['house_number'],
] : null;

$out['last_drive'] = $lastDrive ? [
    'start_city'   => $lastDrive['start_city'],
    'end_city'     => $lastDrive['end_city'],
    'distance'     => $lastDrive['distance'] !== null ? round((float)$lastDrive['distance'], 1) : null,
    'duration_min' => $lastDrive['duration_min'] !== null ? round((float)$lastDrive['duration_min']) : null,
    'soc_start'    => $lastDrive['start_battery_level'] !== null ? (int)$lastDrive['start_battery_level'] : null,
    'soc_end'      => $lastDrive['end_battery_level'] !== null ? (int)$lastDrive['end_battery_level'] : null,
    'start_local'  => $lastDrive['start_local'],
] : null;

if (!empty($GLOBALS['_query_errors'])) {
    $out['query_errors'] = $GLOBALS['_query_errors'];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
