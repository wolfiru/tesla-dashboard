<?php
// Zeitleiste (Übersicht): was das Auto in den letzten 24 h / 48 h / 7 Tagen gemacht hat.
// Grundlage: TeslaMate-Tabellen states (online/asleep/offline), drives (Fahren), charging_processes (Laden).
// Überlappt etwas, gilt: fahren > laden > wach > schlaeft > offline.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/sicherheit.php';
td_api_schuetzen();
session_write_close();

header('Content-Type: application/json; charset=utf-8');

$bereiche = ['24h' => 24, '48h' => 48, '7d' => 168];
$range = isset($_GET['range'], $bereiche[$_GET['range']]) ? $_GET['range'] : '24h';
$stunden = $bereiche[$range];

$db = @pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5",
                          DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS));
if (!$db) { echo json_encode(['ok' => false, 'error' => 'Datenbankverbindung fehlgeschlagen']); exit; }

function alle($db, string $sql, array $p = []): array {
    $r = @pg_query_params($db, $sql, $p);
    $out = [];
    if ($r) while ($z = pg_fetch_assoc($r)) $out[] = $z;
    return $out;
}

$bis = time();
$von = $bis - $stunden * 3600;
// Zeitstempel in TeslaMate: UTC ohne Zeitzone → als Unix-Sekunden holen
$ts  = fn(string $spalte) => "extract(epoch FROM $spalte AT TIME ZONE 'UTC')";
$p   = [gmdate('Y-m-d H:i:s', $von)];

$intervalle = [];   // [von, bis, art, detail]
foreach (alle($db, "SELECT state, {$ts('start_date')} a, {$ts("coalesce(end_date, now() AT TIME ZONE 'UTC')")} e
                    FROM states WHERE coalesce(end_date, now() AT TIME ZONE 'UTC') > \$1::timestamp", $p) as $s) {
    $art = ['online' => 'wach', 'asleep' => 'schlaeft', 'offline' => 'offline'][$s['state']] ?? 'wach';
    $intervalle[] = [(float)$s['a'], (float)$s['e'], $art, null];
}
foreach (alle($db, "SELECT {$ts('d.start_date')} a, {$ts("coalesce(d.end_date, now() AT TIME ZONE 'UTC')")} e,
                           round(d.distance::numeric, 1) km, coalesce(sa.city, sa.name) von, coalesce(ea.city, ea.name) nach,
                           round(f.wh_km) wh_km
                    FROM drives d LEFT JOIN addresses sa ON sa.id = d.start_address_id LEFT JOIN addresses ea ON ea.id = d.end_address_id
                    LEFT JOIN auswertung.fahrt f ON f.drive_id = d.id
                    WHERE coalesce(d.end_date, now() AT TIME ZONE 'UTC') > \$1::timestamp", $p) as $d) {
    $txt = ($d['km'] !== null ? str_replace('.', ',', $d['km']) . ' km' : 'Fahrt')
         . ($d['von'] ? ' · ' . $d['von'] . ($d['nach'] && $d['nach'] !== $d['von'] ? ' → ' . $d['nach'] : '') : '')
         . ($d['wh_km'] ? ' · ' . $d['wh_km'] . ' Wh/km' : '');
    $intervalle[] = [(float)$d['a'], (float)$d['e'], 'fahren', $txt];
}
foreach (alle($db, "SELECT {$ts('start_date')} a, {$ts("coalesce(end_date, now() AT TIME ZONE 'UTC')")} e,
                           round(charge_energy_added, 1) kwh, start_battery_level s, end_battery_level z
                    FROM charging_processes WHERE coalesce(end_date, now() AT TIME ZONE 'UTC') > \$1::timestamp", $p) as $c) {
    $txt = ($c['kwh'] !== null ? '+' . str_replace('.', ',', $c['kwh']) . ' kWh' : 'Laden')
         . ($c['s'] !== null ? ' · ' . $c['s'] . ' → ' . ($c['z'] ?? '…') . ' %' : '');
    $intervalle[] = [(float)$c['a'], (float)$c['e'], 'laden', $txt];
}
pg_close($db);

// Auf das Fenster zuschneiden und nach Rangfolge überlagern
$rang = ['fahren' => 5, 'laden' => 4, 'wach' => 3, 'schlaeft' => 2, 'offline' => 1];
$grenzen = [$von, $bis];
foreach ($intervalle as &$i) {
    $i[0] = max($i[0], $von); $i[1] = min($i[1], $bis);
    if ($i[1] > $i[0]) { $grenzen[] = $i[0]; $grenzen[] = $i[1]; }
}
unset($i);
$grenzen = array_values(array_unique($grenzen));
sort($grenzen);

$abschnitte = [];
for ($k = 0; $k < count($grenzen) - 1; $k++) {
    $a = $grenzen[$k]; $e = $grenzen[$k + 1];
    if ($e - $a < 1) continue;
    $mitte = ($a + $e) / 2;
    $best = null;
    foreach ($intervalle as $i) {
        if ($i[0] <= $mitte && $i[1] > $mitte && ($best === null || $rang[$i[2]] > $rang[$best[2]])) $best = $i;
    }
    $art = $best ? $best[2] : 'unbekannt';
    $detail = $best ? $best[3] : null;
    $letzter = end($abschnitte);
    if ($letzter && $letzter['art'] === $art && $letzter['detail'] === $detail && abs($letzter['bis'] - $a) < 1) {
        $abschnitte[key($abschnitte)]['bis'] = $e;
    } else {
        $abschnitte[] = ['von' => $a, 'bis' => $e, 'art' => $art, 'detail' => $detail];
    }
}

$summe = [];
foreach ($abschnitte as $s) $summe[$s['art']] = ($summe[$s['art']] ?? 0) + ($s['bis'] - $s['von']) / 3600;

echo json_encode([
    'ok' => true, 'range' => $range, 'von' => $von, 'bis' => $bis,
    'abschnitte' => array_map(fn($s) => ['von' => (int)$s['von'], 'bis' => (int)$s['bis'], 'art' => $s['art'], 'detail' => $s['detail']], $abschnitte),
    'stunden' => array_map(fn($h) => round($h, 2), $summe),
], JSON_UNESCAPED_UNICODE);
