<?php
// Strecken für die Karte (Tab „Karte“):
//   ?liste=1            letzte Fahrten für die Auswahl
//   ?fahrt=<id>         eine Fahrt mit Punkten (lat, lon, km/h) für eine nach Tempo gefärbte Linie
//   ?range=heute|7d|30d|90d|1y|all   alle Fahrten des Zeitraums als Linien (heute = seit Mitternacht Wiener Zeit)
// Punkte werden ausgedünnt (TeslaMate speichert beim Fahren ~4 Punkte/s); Anfang und Ende bleiben erhalten.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/sicherheit.php';
td_api_schuetzen();
session_write_close();

header('Content-Type: application/json; charset=utf-8');

$db = @pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5",
                          DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS));
if (!$db) { echo json_encode(['ok' => false, 'error' => 'Datenbankverbindung fehlgeschlagen']); exit; }

function alle($db, string $sql, array $p = []): array {
    $r = @pg_query_params($db, $sql, $p);
    $out = [];
    if ($r) while ($z = pg_fetch_assoc($r)) $out[] = $z;
    return $out;
}

// Gemeinsame Angaben je Fahrt
$fahrtSpalten = "d.id, d.start_date AT TIME ZONE 'UTC' AS start, d.end_date AT TIME ZONE 'UTC' AS ende,
                 round(d.distance::numeric, 1) AS km, round(d.duration_min) AS min, d.speed_max AS v_max,
                 coalesce(sa.city, sa.name) AS von, coalesce(ea.city, ea.name) AS nach,
                 round(f.wh_km) AS wh_km, round(f.v_schnitt) AS v, round(f.temp, 1) AS temp,
                 sp.battery_level AS soc_start, ep.battery_level AS soc_ende";
$fahrtJoins = "FROM drives d
               LEFT JOIN addresses sa ON sa.id = d.start_address_id LEFT JOIN addresses ea ON ea.id = d.end_address_id
               LEFT JOIN auswertung.fahrt f ON f.drive_id = d.id
               LEFT JOIN positions sp ON sp.id = d.start_position_id LEFT JOIN positions ep ON ep.id = d.end_position_id";

// Punkte einer oder mehrerer Fahrten, je Fahrt auf höchstens $max Punkte ausgedünnt
function punkte($db, string $wo, array $p, int $max): array {
    return alle($db, "
        WITH p AS (
          SELECT drive_id, date, latitude, longitude, speed,
                 row_number() OVER (PARTITION BY drive_id ORDER BY date) AS nr,
                 count(*)     OVER (PARTITION BY drive_id) AS n
          FROM positions WHERE $wo AND latitude IS NOT NULL
        )
        SELECT drive_id, round(latitude::numeric, 6) AS lat, round(longitude::numeric, 6) AS lon, speed AS v
        FROM p
        WHERE nr = 1 OR nr = n OR (nr - 1) % greatest(1, ceil(n::numeric / $max)::int) = 0
        ORDER BY drive_id, date", $p);
}

if (isset($_GET['liste'])) {
    alle($db, "SELECT auswertung.aktualisieren()");
    $fahrten = alle($db, "SELECT $fahrtSpalten $fahrtJoins WHERE d.end_date IS NOT NULL ORDER BY d.start_date DESC LIMIT 200");
    echo json_encode(['ok' => true, 'fahrten' => $fahrten], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    exit;
}

if (isset($_GET['fahrt'])) {
    $id = filter_var($_GET['fahrt'], FILTER_VALIDATE_INT);
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'Ungültige Fahrt']); exit; }
    $info = alle($db, "SELECT $fahrtSpalten $fahrtJoins WHERE d.id = \$1", [$id]);
    if (!$info) { echo json_encode(['ok' => false, 'error' => 'Fahrt nicht gefunden']); exit; }
    $pts = punkte($db, 'drive_id = $1', [$id], 3000);
    echo json_encode(['ok' => true, 'fahrt' => $info[0], 'punkte' => array_map(fn($x) => [(float)$x['lat'], (float)$x['lon'], $x['v'] !== null ? (int)$x['v'] : null], $pts)],
                     JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    exit;
}

$bereiche = ['heute' => null, '7d' => '7 days', '30d' => '30 days', '90d' => '90 days', '1y' => '1 year', 'all' => '100 years'];
$range = isset($_GET['range']) && array_key_exists($_GET['range'], $bereiche) ? $_GET['range'] : '30d';
// Beginn des Zeitraums als UTC-Zeitstempel (TeslaMate speichert UTC ohne Zeitzone)
$ab = $range === 'heute'
    ? (new DateTime('today', new DateTimeZone('Europe/Vienna')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
    : (new DateTime('now', new DateTimeZone('UTC')))->modify('-' . $bereiche[$range])->format('Y-m-d H:i:s');
$fahrten = alle($db, "SELECT $fahrtSpalten $fahrtJoins
                      WHERE d.end_date IS NOT NULL AND d.start_date >= \$1::timestamp
                      ORDER BY d.start_date DESC LIMIT 1000", [$ab]);
$linien = [];
if ($fahrten) {
    // Je Fahrt weniger Punkte, je mehr Fahrten (insgesamt grob ≤ 40 000)
    $max = max(60, min(800, intdiv(40000, count($fahrten))));
    $ids = '{' . implode(',', array_map('intval', array_column($fahrten, 'id'))) . '}';
    foreach (punkte($db, 'drive_id = ANY($1::int[])', [$ids], $max) as $x) {
        $linien[$x['drive_id']][] = [(float)$x['lat'], (float)$x['lon'], $x['v'] !== null ? (int)$x['v'] : null];
    }
}
pg_close($db);
echo json_encode(['ok' => true, 'range' => $range, 'fahrten' => $fahrten, 'linien' => $linien],
                 JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
