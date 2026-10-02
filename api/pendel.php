<?php
// Pendel-Auswertung (Tab „Pendeln“): Fahrten zwischen festen Orten, je Richtung getrennt.
// Orte und Radien: Tabelle auswertung.orte, Fahrten: Sicht auswertung.pendel (siehe sql/auswertung.sql).
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/sicherheit.php';
td_api_schuetzen();
session_write_close();

header('Content-Type: application/json; charset=utf-8');

$intervalMap = ['90d' => '90 days', '1y' => '1 year', 'all' => '100 years'];
$range    = isset($_GET['range'], $intervalMap[$_GET['range']]) ? $_GET['range'] : 'all';
$interval = $intervalMap[$range];

$db = @pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5",
                          DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS));
if (!$db) { echo json_encode(['ok' => false, 'error' => 'Datenbankverbindung fehlgeschlagen']); exit; }

function alle($db, string $sql, array $p = []): array {
    $r = @pg_query_params($db, $sql, $p);
    if (!$r) return [];
    $out = [];
    while ($z = pg_fetch_assoc($r)) $out[] = $z;
    return $out;
}

// Neue Fahrten verdichten, damit Verbrauchswerte vorhanden sind
alle($db, "SELECT auswertung.aktualisieren()");

$orte = alle($db, "SELECT name, round(radius_m / 1000.0, 1) AS radius_km FROM auswertung.orte ORDER BY name");
if (!$orte) { echo json_encode(['ok' => false, 'error' => 'Pendel-Auswertung fehlt – auswertung.sql einspielen']); exit; }

$w = "start_date >= (now() AT TIME ZONE 'UTC') - \$1::interval";
$lokal = "(start_date AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Vienna')";

$richtungen = alle($db, "
    SELECT richtung, von, nach, count(*) AS n,
           round(avg(km), 1) AS km, round(avg(duration_min)) AS min,
           round(sum(kwh) / nullif(sum(km) FILTER (WHERE kwh IS NOT NULL), 0) * 1000) AS wh_km,
           round(avg(kwh), 2) AS kwh,
           round(avg(v_schnitt)) AS v, round(avg(temp), 1) AS temp,
           round(avg(soc_start - soc_ende), 1) AS soc_verbrauch,
           round(min(wh_km)) AS wh_km_min, round(max(wh_km)) AS wh_km_max,
           round(min(duration_min)) AS min_min, round(max(duration_min)) AS min_max,
           max(start_date AT TIME ZONE 'UTC') AS letzte
    FROM auswertung.pendel WHERE $w
    GROUP BY richtung, von, nach ORDER BY richtung", [$interval]);

$fahrten = alle($db, "
    SELECT drive_id, richtung, start_date AT TIME ZONE 'UTC' AS start,
           round(km, 1) AS km, round(duration_min) AS min, round(wh_km) AS wh_km, round(kwh, 2) AS kwh,
           round(v_schnitt) AS v, speed_max AS v_max, round(temp, 1) AS temp,
           soc_start, soc_ende, round(klima_anteil * 100) AS klima_pct, ascent, descent,
           round(extract(hour FROM $lokal) + extract(minute FROM $lokal) / 60.0, 2) AS abfahrt_h
    FROM auswertung.pendel WHERE $w ORDER BY start_date DESC LIMIT 400", [$interval]);

$temp = alle($db, "
    SELECT richtung, (floor(temp / 5) * 5)::int AS temp_bin, count(*) AS n,
           round(sum(kwh) / nullif(sum(km), 0) * 1000) AS wh_km
    FROM auswertung.pendel WHERE $w AND temp IS NOT NULL AND kwh IS NOT NULL
    GROUP BY 1, 2 ORDER BY 2, 1", [$interval]);

$stunde = alle($db, "
    SELECT richtung, extract(hour FROM $lokal)::int AS stunde, count(*) AS n, round(avg(duration_min)) AS min
    FROM auswertung.pendel WHERE $w GROUP BY 1, 2 ORDER BY 2, 1", [$interval]);

$monate = alle($db, "
    SELECT richtung, to_char(date_trunc('month', $lokal), 'YYYY-MM') AS monat, count(*) AS n,
           round(sum(kwh) / nullif(sum(km), 0) * 1000) AS wh_km, round(avg(duration_min)) AS min, round(avg(temp), 1) AS temp
    FROM auswertung.pendel WHERE $w GROUP BY 1, 2 ORDER BY 2, 1", [$interval]);

pg_close($db);
echo json_encode(['ok' => true, 'range' => $range, 'orte' => $orte, 'richtungen' => $richtungen, 'fahrten' => $fahrten,
                  'temp' => $temp, 'stunde' => $stunde, 'monate' => $monate],
                 JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
