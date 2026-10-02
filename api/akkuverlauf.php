<?php
// Akkuverlauf der letzten Stunden für den Tab Batterie: SOC und Reichweite (gemittelt je Zeitschritt),
// dazu Fahrten und Ladungen als Zeitbereiche. ?h=12|24|48|168
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/sicherheit.php';
td_api_schuetzen();
session_write_close();
header('Content-Type: application/json; charset=utf-8');

$h = (int)($_GET['h'] ?? 24);
if (!in_array($h, [12, 24, 48, 168], true)) $h = 24;
$schritt = $h <= 24 ? 5 : ($h <= 48 ? 10 : 30);   // Minuten je Punkt

$db = @pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5", DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS));
if (!$db) { echo json_encode(['ok' => false, 'error' => 'Datenbankverbindung fehlgeschlagen']); exit; }
function zeilen($db, string $sql, array $p): array {
    $r = pg_query_params($db, $sql, $p); $out = [];
    while ($r && ($z = pg_fetch_assoc($r))) $out[] = $z;
    return $out;
}
$tz = TIMEZONE;
$punkte = zeilen($db, "
    SELECT to_char(to_timestamp(floor(extract(epoch FROM date) / (\$2 * 60)) * \$2 * 60) AT TIME ZONE \$3, 'YYYY-MM-DD\"T\"HH24:MI') AS t,
           round(avg(battery_level)::numeric, 1) AS soc, round(avg(rated_battery_range_km)::numeric, 1) AS km,
           round(avg(outside_temp)::numeric, 1) AS temp
    FROM positions
    WHERE car_id = 1 AND date > (now() AT TIME ZONE 'UTC') - make_interval(hours => \$1) AND battery_level IS NOT NULL
    GROUP BY 1 ORDER BY 1", [$h, $schritt, $tz]);
$bereich = fn(string $tab) => zeilen($db, "
    SELECT to_char(start_date AT TIME ZONE 'UTC' AT TIME ZONE \$2, 'YYYY-MM-DD\"T\"HH24:MI') AS von,
           to_char(coalesce(end_date, now() AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' AT TIME ZONE \$2, 'YYYY-MM-DD\"T\"HH24:MI') AS bis
    FROM $tab WHERE car_id = 1 AND coalesce(end_date, now() AT TIME ZONE 'UTC') > (now() AT TIME ZONE 'UTC') - make_interval(hours => \$1)
    ORDER BY start_date", [$h, $tz]);
echo json_encode([
    'ok' => true, 'stunden' => $h, 'schritt_min' => $schritt,
    'punkte' => array_map(fn($p) => ['t' => $p['t'], 'soc' => (float)$p['soc'], 'km' => (float)$p['km'],
                                     'temp' => $p['temp'] !== null ? (float)$p['temp'] : null], $punkte),
    'fahrten' => $bereich('drives'),
    'ladungen' => $bereich('charging_processes'),
]);
