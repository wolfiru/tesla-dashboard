<?php
// Statistik-Tab: liest die verdichteten Daten aus dem Schema "auswertung" der TeslaMate-DB
// (siehe sql/auswertung.sql). Neue Fahrten werden bei jedem Aufruf nachgezogen.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/sicherheit.php';
td_api_schuetzen();
session_write_close();

header('Content-Type: application/json; charset=utf-8');

$intervalMap = [
    '30d' => '30 days',
    '90d' => '90 days',
    '1y'  => '1 year',
    'all' => '100 years',
];
$range    = isset($_GET['range'], $intervalMap[$_GET['range']]) ? $_GET['range'] : 'all';
$interval = $intervalMap[$range];

$connstr = sprintf(
    "host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5",
    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
);
$db = @pg_connect($connstr);
if (!$db) {
    echo json_encode(['ok' => false, 'error' => 'Datenbankverbindung fehlgeschlagen']);
    exit;
}

function fetchAll($db, $sql, $params = []) {
    $res = @pg_query_params($db, $sql, $params);
    if (!$res) return [];
    $rows = [];
    while ($row = pg_fetch_assoc($res)) $rows[] = $row;
    return $rows;
}
function fetchOne($db, $sql, $params = []) {
    $rows = fetchAll($db, $sql, $params);
    return $rows[0] ?? null;
}

// Neue, abgeschlossene Fahrten verdichten (schnell, wenn nichts Neues da ist)
$neu = fetchOne($db, "SELECT auswertung.aktualisieren() AS n");
if ($neu === null) {
    echo json_encode(['ok' => false, 'error' => 'Auswertungs-Schema fehlt – auswertung.sql einspielen']);
    exit;
}

$since = "(now() AT TIME ZONE 'UTC') - \$1::interval";

// Verbrauchsmatrix Temperatur × Geschwindigkeit
$matrix = fetchAll($db, "
    SELECT b.temp_bin, b.speed_bin,
           round(sum(b.km), 1)                                    AS km,
           round(sum(b.kwh) / nullif(sum(b.km), 0) * 1000)        AS wh_km,
           round(sum(b.sek) / 60)                                 AS minuten
    FROM auswertung.fahrt_bins b JOIN auswertung.fahrt f USING (drive_id)
    WHERE f.start_date >= $since
    GROUP BY 1, 2 ORDER BY 1, 2", [$interval]);

// Kurven nach Temperatur (mit/ohne Klima) und nach Geschwindigkeit
$temp = fetchAll($db, "
    SELECT b.temp_bin,
           round(sum(b.km), 1)                                                        AS km,
           round(sum(b.kwh) / nullif(sum(b.km), 0) * 1000)                            AS wh_km,
           -- mit/ohne Klima nur ab 3 km Datenbasis je Temperaturbereich (sonst Zufallswerte)
           CASE WHEN sum(b.km) FILTER (WHERE b.klima) >= 3
                THEN round(sum(b.kwh) FILTER (WHERE b.klima) / sum(b.km) FILTER (WHERE b.klima) * 1000) END         AS wh_km_klima,
           CASE WHEN sum(b.km) FILTER (WHERE NOT b.klima) >= 3
                THEN round(sum(b.kwh) FILTER (WHERE NOT b.klima) / sum(b.km) FILTER (WHERE NOT b.klima) * 1000) END AS wh_km_ohne,
           round(sum(b.km) FILTER (WHERE b.klima), 1)                                 AS km_klima
    FROM auswertung.fahrt_bins b JOIN auswertung.fahrt f USING (drive_id)
    WHERE f.start_date >= $since
    GROUP BY 1 ORDER BY 1", [$interval]);

$speed = fetchAll($db, "
    SELECT b.speed_bin,
           round(sum(b.km), 1)                             AS km,
           round(sum(b.kwh) / nullif(sum(b.km), 0) * 1000) AS wh_km
    FROM auswertung.fahrt_bins b JOIN auswertung.fahrt f USING (drive_id)
    WHERE f.start_date >= $since
    GROUP BY 1 ORDER BY 1", [$interval]);

// Summen & Rekorde
$summe = fetchOne($db, "
    SELECT count(*) AS fahrten, round(sum(km), 1) AS km, round(sum(kwh), 1) AS kwh,
           round(sum(kwh_rekup), 1) AS kwh_rekup,
           round(sum(kwh) / nullif(sum(km), 0) * 1000) AS wh_km,
           round(sum(ascent)) AS hoehenmeter,
           round(sum(dauer_min) / 60, 1) AS stunden
    FROM auswertung.fahrt WHERE start_date >= $since", [$interval]);

$rekordSql = function ($order, $where = 'km >= 5') use ($since) {
    return "SELECT f.drive_id, f.start_date AT TIME ZONE 'UTC' AS start_date, round(f.km, 1) AS km, round(f.wh_km) AS wh_km,
                   round(f.v_schnitt) AS v, round(f.temp, 1) AS temp, round(f.dauer_min) AS dauer_min,
                   coalesce(sa.city, sa.name) AS von, coalesce(ea.city, ea.name) AS nach
            FROM auswertung.fahrt f JOIN drives d ON d.id = f.drive_id
            LEFT JOIN addresses sa ON sa.id = d.start_address_id
            LEFT JOIN addresses ea ON ea.id = d.end_address_id
            WHERE f.start_date >= $since AND $where
            ORDER BY $order LIMIT 1";
};
$rekorde = [
    'laengste'   => fetchOne($db, $rekordSql('f.km DESC', 'true'), [$interval]),
    'sparsamste' => fetchOne($db, $rekordSql('f.wh_km ASC'), [$interval]),
    'durstigste' => fetchOne($db, $rekordSql('f.wh_km DESC'), [$interval]),
    'schnellste' => fetchOne($db, $rekordSql('f.v_schnitt DESC'), [$interval]),
    'kaelteste'  => fetchOne($db, $rekordSql('f.temp ASC NULLS LAST', 'f.km >= 1 AND f.temp IS NOT NULL'), [$interval]),
    'hoechste'   => fetchOne($db, "SELECT d.id AS drive_id, d.start_date AT TIME ZONE 'UTC' AS start_date, round(max(p.elevation)) AS hoehe
                                   FROM positions p JOIN drives d ON d.id = p.drive_id
                                   WHERE d.start_date >= $since AND p.elevation IS NOT NULL
                                   GROUP BY d.id ORDER BY 3 DESC LIMIT 1", [$interval]),
];

// Fahrten für Streudiagramme (Höhenmeter, Geschwindigkeit, Temperatur)
$fahrten = fetchAll($db, "
    SELECT drive_id, start_date AT TIME ZONE 'UTC' AS start_date, round(km, 1) AS km, round(wh_km) AS wh_km, round(v_schnitt) AS v,
           round(temp, 1) AS temp, ascent, descent,
           round((coalesce(ascent, 0) - coalesce(descent, 0)) / nullif(km, 0), 1) AS hm_pro_km,
           round(klima_anteil * 100) AS klima_pct
    FROM auswertung.fahrt
    WHERE start_date >= $since AND km >= 1 AND wh_km IS NOT NULL
    ORDER BY start_date DESC LIMIT 500", [$interval]);

// Monate
$monate = fetchAll($db, "
    SELECT to_char(monat, 'YYYY-MM') AS monat, fahrten, round(km, 1) AS km, round(kwh, 1) AS kwh,
           round(wh_km) AS wh_km, round(temp, 1) AS temp, ladungen,
           round(geladen_kwh, 1) AS geladen_kwh, round(bezogen_kwh, 1) AS bezogen_kwh,
           round(kosten, 2) AS kosten
    FROM auswertung.monat
    WHERE monat >= date_trunc('month', now() - \$1::interval)
    ORDER BY monat", [$interval]);

// Ladevorgänge: Verluste, Kapazität, Reichweite bei 100 %
$ladungen = fetchAll($db, "
    SELECT id, start_date AT TIME ZONE 'UTC' AS start_date, dc::int AS dc, round(p_avg, 1) AS p_avg, phasen, ampere,
           round(effizienz_pct, 1) AS effizienz_pct, round(outside_temp_avg, 1) AS temp,
           round(charge_energy_added, 2) AS kwh, start_battery_level AS soc_von, end_battery_level AS soc_bis,
           round(kapazitaet_kwh, 1) AS kapazitaet_kwh, round(reichweite_100_km) AS reichweite_100_km,
           round(km_stand) AS km_stand, ort, round(cost, 2) AS kosten
    FROM auswertung.ladung WHERE start_date >= $since ORDER BY start_date", [$interval]);

// Standverlust
$stand = fetchOne($db, "
    SELECT count(*) AS anzahl, round(sum(stunden)) AS stunden,
           round(sum(verlust_km) / nullif(sum(stunden), 0) * 24, 1)  AS km_pro_tag,
           round(sum(verlust_kwh) / nullif(sum(stunden), 0) * 24, 2) AS kwh_pro_tag,
           round(sum(verlust_km), 1) AS km_gesamt
    FROM auswertung.standverlust WHERE von >= $since", [$interval]);
$standListe = fetchAll($db, "
    SELECT von AT TIME ZONE 'UTC' AS von, round(stunden, 1) AS stunden, round(verlust_km, 1) AS verlust_km,
           round(verlust_km / nullif(stunden, 0) * 24, 1) AS km_pro_tag
    FROM auswertung.standverlust WHERE von >= $since ORDER BY von DESC LIMIT 60", [$interval]);

// Reifendruck je Tag
// abgeschlossene Tage aus auswertung.tag_reifen + der laufende Tag direkt aus den Messwerten
$reifen = fetchAll($db, "
    SELECT datum, fl, fr, rl, rr, temp FROM auswertung.tag_reifen
    WHERE datum >= (now() - \$1::interval)::date
      AND datum < (now() AT TIME ZONE 'Europe/Vienna')::date
    UNION ALL
    SELECT (now() AT TIME ZONE 'Europe/Vienna')::date,
           round(avg(tpms_pressure_fl), 2), round(avg(tpms_pressure_fr), 2),
           round(avg(tpms_pressure_rl), 2), round(avg(tpms_pressure_rr), 2), round(avg(outside_temp), 1)
    FROM positions
    WHERE date >= (date_trunc('day', now() AT TIME ZONE 'Europe/Vienna') AT TIME ZONE 'Europe/Vienna') AT TIME ZONE 'UTC'
    HAVING count(tpms_pressure_fl) > 0
    ORDER BY datum", [$interval]);

// Grundlagen für den Reichweitenrechner (immer über alle Daten)
$basis = fetchOne($db, "
    SELECT (SELECT round(percentile_cont(0.5) WITHIN GROUP (ORDER BY kapazitaet_kwh)::numeric, 1)
              FROM (SELECT kapazitaet_kwh FROM auswertung.ladung WHERE kapazitaet_kwh IS NOT NULL
                    ORDER BY start_date DESC LIMIT 10) x)                        AS kapazitaet_kwh,
           (SELECT round(efficiency::numeric * 1000) FROM cars ORDER BY id LIMIT 1) AS werk_wh_km,
           (SELECT round(sum(kwh) / nullif(sum(km), 0) * 1000) FROM auswertung.fahrt_bins) AS wh_km_gesamt,
           (SELECT round(max(reichweite_100_km)) FROM
              (SELECT reichweite_100_km FROM auswertung.ladung WHERE reichweite_100_km IS NOT NULL
               ORDER BY start_date DESC LIMIT 10) y)                             AS reichweite_100_km,
           (SELECT battery_level FROM positions ORDER BY date DESC LIMIT 1)      AS soc_aktuell");
$matrixAlle = fetchAll($db, "
    SELECT temp_bin, speed_bin, round(sum(km), 1) AS km,
           round(sum(kwh) / nullif(sum(km), 0) * 1000) AS wh_km
    FROM auswertung.fahrt_bins GROUP BY 1, 2");

pg_close($db);

echo json_encode([
    'ok'          => true,
    'range'       => $range,
    'neu'         => (int)$neu['n'],
    'summe'       => $summe,
    'rekorde'     => $rekorde,
    'matrix'      => $matrix,
    'temp'        => $temp,
    'speed'       => $speed,
    'fahrten'     => $fahrten,
    'monate'      => $monate,
    'ladungen'    => $ladungen,
    'stand'       => $stand,
    'stand_liste' => $standListe,
    'reifen'      => $reifen,
    'rechner'     => ['basis' => $basis, 'matrix' => $matrixAlle],
], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
