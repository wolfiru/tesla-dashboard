<?php
// Auffälligkeiten der letzten 48 h (Karte oben in der Übersicht).
// Vergleicht die letzten zwei Tage mit dem eigenen Normalwert und meldet nur deutliche Abweichungen.
// Quellen: TeslaMate-DB (inkl. Schema auswertung), TeslaMate-MQTT (lib/fahrzeugwerte.php), DaheimLader (Wallbox, optional).
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/sicherheit.php';
require_once dirname(__DIR__) . '/lib/fahrzeugwerte.php';
td_api_schuetzen();
session_write_close();

header('Content-Type: application/json; charset=utf-8');

// Schwellen – hier anpassen (Tests können sie per define('TD_AUFF_SCHWELLEN', [...]) überschreiben)
$S = array_merge([
    'fenster_h'          => 48,    // betrachteter Zeitraum
    'stand_min_std'      => 6,     // Parkphase erst ab so vielen Stunden bewerten
    'stand_km_tag_abs'   => 6.0,   // ohne eigenen Normalwert: ab so viel km Reichweite/Tag melden
    'stand_km_tag_min'   => 3.0,   // mit Normalwert: nie unter dieser Schwelle melden …
    'stand_faktor'       => 2.0,   // … und erst ab dem Doppelten des üblichen Werts
    'reifen_hinweis'     => 0.15,  // bar unter Sollwert (TPMS_NOMINAL_BAR): orange „etwas niedrig“
    'reifen_unter_soll'  => 0.30,  // bar unter Sollwert: rot „zu niedrig“
    'reifen_spreizung'   => 0.25,  // bar Unterschied zwischen den Reifen
    'reifen_verlust'     => 0.15,  // bar Druckverlust eines Reifens gegenüber den anderen (5–10 Tage)
    'verbrauch_plus_pct' => 20,    // % über dem erwarteten Verbrauch bei gleichen Bedingungen
    'verbrauch_min_km'   => 20,    // erst ab so vielen km in den letzten 48 h bewerten
    'lade_min_kwh'       => 2.0,   // kleinere Ladungen (Vorheizen am Kabel) nicht bewerten
    'lade_eff_minus_pp'  => 5,     // Wirkungsgrad um so viele %-Punkte unter dem üblichen
    'lade_leistung_pct'  => 75,    // Ø-Ladeleistung unter so viel % der üblichen
    'wach_std'           => 3,     // Auto seit so vielen Stunden wach, ohne zu fahren oder zu laden
    'wach_24h_std'       => 6,     // insgesamt so viele Stunden wach ohne Fahren/Laden in 24 h
    'kabel_verlust_pct'  => 4.0,   // Wallbox: Kabelverlust in %
    'kabel_delta_v'      => 9.0,   // Wallbox: Spannungsabfall Hausanschluss → Wallbox je Phase in V
    'akku_niedrig'       => 20,    // % Akku, wenn nicht angesteckt
], defined('TD_AUFF_SCHWELLEN') ? TD_AUFF_SCHWELLEN : []);

$db = @pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5",
                          DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS));
if (!$db) { echo json_encode(['ok' => false, 'error' => 'Datenbankverbindung fehlgeschlagen']); exit; }

function eine($db, string $sql, array $p = []): ?array {
    $r = @pg_query_params($db, $sql, $p);
    return ($r && pg_num_rows($r)) ? pg_fetch_assoc($r) : null;
}
function alle($db, string $sql, array $p = []): array {
    $r = @pg_query_params($db, $sql, $p);
    $out = [];
    if ($r) while ($z = pg_fetch_assoc($r)) $out[] = $z;
    return $out;
}
function median(array $w): ?float {
    $w = array_values(array_filter($w, fn($x) => $x !== null && $x !== ''));
    if (!$w) return null;
    sort($w);
    $n = count($w);
    return $n % 2 ? (float)$w[intdiv($n, 2)] : ((float)$w[$n / 2 - 1] + (float)$w[$n / 2]) / 2;
}
function zahl($x, int $d = 1): string { return number_format((float)$x, $d, ',', '.'); }
function http_json(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$raw, true);
    return $code === 200 && is_array($j) ? $j : null;
}

alle($db, "SELECT auswertung.aktualisieren()");

$hinweise = [];
$geprueft = [];   // Prüfung => ok | auffaellig | keine_daten
$RANG = ['warn' => 3, 'hinweis' => 2, 'info' => 1];   // rot, orange, blau
$melde = function (string $pruefung, string $stufe, string $titel, string $text) use (&$hinweise, &$geprueft, $RANG) {
    $hinweise[] = ['pruefung' => $pruefung, 'stufe' => $stufe, 'titel' => $titel, 'text' => $text];
    $alt = $geprueft[$pruefung] ?? null;
    if (!isset($RANG[$alt]) || $RANG[$stufe] > $RANG[$alt]) $geprueft[$pruefung] = $stufe;
};
$ok = function (string $pruefung, bool $daten = true) use (&$geprueft) {
    if (!isset($geprueft[$pruefung])) $geprueft[$pruefung] = $daten ? 'ok' : 'keine_daten';
};
$fenster = "(now() AT TIME ZONE 'UTC') - make_interval(hours => \$1)";

// MQTT-Werte (Sentry, Akku, angesteckt) – direkt oder über ioBroker, siehe lib/fahrzeugwerte.php
$mqtt = (td_fahrzeugwerte() ?? ['werte' => []])['werte'];

// ── 1. Standverlust ─────────────────────────────────────────────────────────
$basis = alle($db, "SELECT verlust_km / nullif(stunden, 0) * 24 AS km_tag FROM auswertung.standverlust
                    WHERE von < $fenster AND stunden >= \$2", [$S['fenster_h'], $S['stand_min_std']]);
$normal = count($basis) >= 3 ? median(array_column($basis, 'km_tag')) : null;
$grenze = $normal !== null ? max($S['stand_km_tag_min'], $S['stand_faktor'] * $normal) : $S['stand_km_tag_abs'];
// Mögliche Ursachen einer Parkphase (von/bis = UTC wie in TeslaMate): Wachzeit, Hitze im Innenraum, Wächtermodus (nur laufend).
$ursachen = function (string $von, string $bis) use ($db, $mqtt): string {
    $t0 = strtotime($von . ' UTC'); $t1 = strtotime($bis . ' UTC');
    $teile = [];
    // Wächtermodus: TeslaMate speichert ihn nicht in der DB, und die MQTT-„letzte Änderung“ wird auch beim Aufwachen neu
    // gesetzt – verlässlich ist nur der aktuelle Wert, und der zählt nur für die laufende Parkphase.
    if (($mqtt['sentry_mode'] ?? false) === true && $t1 >= time() - 1800) $teile[] = 'der Wächtermodus ist an – er hält das Auto wach';
    $wach = eine($db, "SELECT coalesce(sum(extract(epoch FROM least(coalesce(end_date, now() AT TIME ZONE 'UTC'), \$2::timestamp)
                                     - greatest(start_date, \$1::timestamp))) / 3600, 0) AS h
                       FROM states WHERE state = 'online' AND start_date < \$2::timestamp
                         AND coalesce(end_date, now() AT TIME ZONE 'UTC') > \$1::timestamp", [$von, $bis]);
    $h = (float)($wach['h'] ?? 0); $dauer = max(($t1 - $t0) / 3600, 0.01);
    if ($h >= 2 && $h / $dauer > 0.3) $teile[] = 'das Auto war ' . zahl($h, 1) . ' von ' . zahl($dauer, 0) . ' h wach statt zu schlafen';
    $innen = eine($db, "SELECT max(inside_temp) AS t FROM positions WHERE date BETWEEN \$1::timestamp AND \$2::timestamp", [$von, $bis]);
    if ($innen && $innen['t'] !== null && (float)$innen['t'] >= 30) $teile[] = 'innen bis ' . zahl($innen['t'], 0) . ' °C (Überhitzungsschutz läuft dann mit)';
    return $teile ? ' Mögliche Ursache: ' . implode('; ', $teile) . '.' : '';
};
$standText = fn($kmTag, string $von, string $bis) => zahl($kmTag) . ' km Reichweite pro Tag' . ($normal !== null ? ' (üblich ' . zahl($normal) . ' km/Tag)' : '')
                         . '.' . $ursachen($von, $bis);

// laufende Parkphase: seit Ende der letzten Fahrt bzw. Ladung bis zur letzten Messung
$lauf = eine($db, "
    WITH ref AS (
      SELECT * FROM (
        SELECT end_date AS t, end_rated_range_km::numeric AS km FROM drives WHERE end_date IS NOT NULL
        UNION ALL
        SELECT end_date, end_rated_range_km::numeric FROM charging_processes WHERE end_date IS NOT NULL AND end_rated_range_km IS NOT NULL
      ) x ORDER BY t DESC LIMIT 1
    ), jetzt AS (
      SELECT date AS t, rated_battery_range_km::numeric AS km FROM positions
      WHERE rated_battery_range_km IS NOT NULL ORDER BY date DESC LIMIT 1
    )
    SELECT ref.km - jetzt.km AS verlust_km, extract(epoch FROM jetzt.t - ref.t) / 3600 AS stunden, ref.t AS von, jetzt.t AS bis
    FROM ref, jetzt WHERE jetzt.t > ref.t");
$laufGemeldet = false;
if ($lauf && $lauf['stunden'] >= $S['stand_min_std'] && $lauf['verlust_km'] > 0) {
    $kmTag = $lauf['verlust_km'] / $lauf['stunden'] * 24;
    if ($kmTag > $grenze) {
        $melde('standverlust', 'warn', 'Hoher Standverlust (aktuelle Parkphase)',
               zahl($lauf['verlust_km']) . ' km in ' . zahl($lauf['stunden'], 0) . ' h = ' . $standText($kmTag, $lauf['von'], $lauf['bis']));
        $laufGemeldet = true;
    }
}
if (!$laufGemeldet) {
    foreach (alle($db, "SELECT von, bis, stunden, verlust_km FROM auswertung.standverlust
                        WHERE von >= $fenster AND stunden >= \$2 ORDER BY von DESC", [$S['fenster_h'], $S['stand_min_std']]) as $p) {
        $kmTag = $p['verlust_km'] / $p['stunden'] * 24;
        if ($kmTag > $grenze) {
            $melde('standverlust', 'warn', 'Hoher Standverlust beim Parken',
                   zahl($p['verlust_km']) . ' km in ' . zahl($p['stunden'], 0) . ' h = ' . $standText($kmTag, $p['von'], $p['bis']));
            break;
        }
    }
}
$ok('standverlust', (bool)$lauf || $basis);

// ── 2. Reifendruck ──────────────────────────────────────────────────────────
$rad = ['fl' => 'vorne links', 'fr' => 'vorne rechts', 'rl' => 'hinten links', 'rr' => 'hinten rechts'];
$jetztR = eine($db, "SELECT avg(tpms_pressure_fl) fl, avg(tpms_pressure_fr) fr, avg(tpms_pressure_rl) rl, avg(tpms_pressure_rr) rr, avg(outside_temp) temp
                     FROM positions WHERE tpms_pressure_fl IS NOT NULL
                       AND date >= (SELECT max(date) FROM positions WHERE tpms_pressure_fl IS NOT NULL) - interval '12 hours'
                       AND date >= $fenster", [$S['fenster_h']]);
if ($jetztR && $jetztR['fl'] !== null) {
    $soll = (float)TPMS_NOMINAL_BAR;
    $zuNiedrig = []; $etwasNiedrig = [];
    foreach ($rad as $k => $name) {
        $v = (float)$jetztR[$k];
        if ($v < $soll - $S['reifen_unter_soll'])  $zuNiedrig[]    = "$name " . zahl($v, 2) . ' bar';
        elseif ($v < $soll - $S['reifen_hinweis']) $etwasNiedrig[] = "$name " . zahl($v, 2) . ' bar';
    }
    if ($zuNiedrig) $melde('reifen', 'warn', 'Reifendruck zu niedrig', implode(', ', $zuNiedrig) . ' (Soll ' . zahl($soll, 1)
                           . ' bar). Bald nachfüllen: kostet Reichweite und Reifen, im Extremfall Sicherheit.');
    if ($etwasNiedrig) $melde('reifen', 'hinweis', 'Reifendruck etwas niedrig', implode(', ', $etwasNiedrig) . ' (Soll ' . zahl($soll, 1)
                              . ' bar). Bei Gelegenheit nachfüllen, am besten bei kalten Reifen.');
    $werte = array_map(fn($k) => (float)$jetztR[$k], array_keys($rad));
    if (max($werte) - min($werte) > $S['reifen_spreizung']) {
        $melde('reifen', 'info', 'Reifendruck ungleich', 'Unterschied ' . zahl(max($werte) - min($werte), 2) . ' bar zwischen den Reifen ('
               . implode(', ', array_map(fn($k, $n) => "$n " . zahl($jetztR[$k], 2), array_keys($rad), $rad)) . ')');
    }
    // schleichender Verlust: Veränderung je Reifen gegenüber 5–10 Tage vorher, relativ zu den anderen (Temperatur fällt heraus)
    $frueher = eine($db, "SELECT avg(tpms_pressure_fl) fl, avg(tpms_pressure_fr) fr, avg(tpms_pressure_rl) rl, avg(tpms_pressure_rr) rr
                          FROM positions WHERE tpms_pressure_fl IS NOT NULL
                            AND date BETWEEN (now() AT TIME ZONE 'UTC') - interval '10 days' AND (now() AT TIME ZONE 'UTC') - interval '5 days'");
    if ($frueher && $frueher['fl'] !== null) {
        $delta = [];
        foreach ($rad as $k => $n) $delta[$k] = (float)$jetztR[$k] - (float)$frueher[$k];
        foreach ($rad as $k => $n) {
            $andere = median(array_values(array_diff_key($delta, [$k => 1])));
            if ($andere !== null && $andere - $delta[$k] >= $S['reifen_verlust']) {
                $melde('reifen', 'warn', "Reifen $n verliert Luft",
                       zahl($delta[$k], 2) . ' bar in 5–10 Tagen, die anderen Reifen ' . zahl($andere, 2) . ' bar. Auf Beschädigung/Ventil prüfen.');
            }
        }
    }
    $ok('reifen');
} else {
    $ok('reifen', false);
}

// ── 3. Verbrauch der letzten Fahrten ────────────────────────────────────────
$neu = eine($db, "SELECT sum(km) km, sum(kwh) kwh, count(*) n FROM auswertung.fahrt WHERE start_date >= $fenster AND km > 0", [$S['fenster_h']]);
if ($neu && (float)$neu['km'] >= $S['verbrauch_min_km'] && $neu['kwh'] !== null) {
    // erwarteter Verbrauch: gleiche Temperatur-/Geschwindigkeitsbereiche, Normalwert aus älteren Fahrten
    $e = eine($db, "
        WITH neu AS (
          SELECT b.temp_bin, b.speed_bin, sum(b.km) km, sum(b.kwh) kwh
          FROM auswertung.fahrt_bins b JOIN auswertung.fahrt f USING (drive_id)
          WHERE f.start_date >= $fenster GROUP BY 1, 2
        ), alt AS (
          SELECT b.temp_bin, b.speed_bin, sum(b.kwh) / nullif(sum(b.km), 0) wh
          FROM auswertung.fahrt_bins b JOIN auswertung.fahrt f USING (drive_id)
          WHERE f.start_date < $fenster GROUP BY 1, 2 HAVING sum(b.km) >= 3
        )
        SELECT sum(neu.kwh) FILTER (WHERE alt.wh IS NOT NULL) ist, sum(neu.km * alt.wh) erwartet,
               sum(neu.km) FILTER (WHERE alt.wh IS NOT NULL) / nullif(sum(neu.km), 0) abdeckung
        FROM neu LEFT JOIN alt USING (temp_bin, speed_bin)", [$S['fenster_h']]);
    $whNeu = $neu['kwh'] / $neu['km'] * 1000;
    if ($e && $e['erwartet'] > 0 && $e['abdeckung'] >= 0.6) {
        $plus = ($e['ist'] / $e['erwartet'] - 1) * 100;
        if ($plus >= $S['verbrauch_plus_pct']) {
            $melde('verbrauch', 'warn', 'Verbrauch deutlich höher als üblich',
                   zahl($neu['km'], 0) . ' km mit ' . zahl($whNeu, 0) . ' Wh/km: +' . zahl($plus, 0)
                   . ' % gegenüber deinem Verbrauch bei gleichen Temperaturen und Geschwindigkeiten. Mögliche Ursachen: Heizung/Klima, Reifendruck, Dachbox, Wind.');
        }
    } else {
        $allg = eine($db, "SELECT sum(kwh) / nullif(sum(km), 0) * 1000 wh FROM auswertung.fahrt WHERE start_date < $fenster AND km > 1", [$S['fenster_h']]);
        if ($allg && $allg['wh'] > 0 && ($whNeu / $allg['wh'] - 1) * 100 >= $S['verbrauch_plus_pct'] * 1.5) {
            $melde('verbrauch', 'info', 'Verbrauch höher als im Durchschnitt',
                   zahl($neu['km'], 0) . ' km mit ' . zahl($whNeu, 0) . ' Wh/km (Durchschnitt ' . zahl($allg['wh'], 0)
                   . ' Wh/km). Für einen fairen Vergleich fehlen noch Fahrten bei ähnlichen Bedingungen.');
        }
    }
    $ok('verbrauch');
} else {
    $ok('verbrauch', false);
}

// ── 4./5. Laden: Wirkungsgrad, Phasen, Leistung ─────────────────────────────
$ladungen = alle($db, "SELECT id, start_date >= $fenster AS neu, effizienz_pct, p_avg, phasen, dc, charge_energy_added
                       FROM auswertung.ladung WHERE charge_energy_added >= \$2 AND effizienz_pct IS NOT NULL
                       ORDER BY start_date DESC LIMIT 20", [$S['fenster_h'], $S['lade_min_kwh']]);
$neuL = array_values(array_filter($ladungen, fn($l) => $l['neu'] === 't'));
$altL = array_values(array_filter($ladungen, fn($l) => $l['neu'] !== 't' && $l['dc'] !== 't'));
if ($neuL) {
    $l = $neuL[0];
    $effNormal = count($altL) >= 3 ? median(array_column(array_slice($altL, 0, 10), 'effizienz_pct')) : null;
    if ($effNormal !== null && $l['dc'] !== 't' && (float)$l['effizienz_pct'] < $effNormal - $S['lade_eff_minus_pp']) {
        $melde('laden', 'warn', 'Hohe Ladeverluste', 'Letzte Ladung: ' . zahl($l['effizienz_pct'], 1) . ' % im Akku angekommen (üblich '
               . zahl($effNormal, 1) . ' %). Mögliche Ursachen: Kälte (Akkuheizung), sehr niedriger Ladestrom, Klima während des Ladens.');
    }
    if ($l['dc'] !== 't' && $altL) {
        $phasenUeblich = median(array_column($altL, 'phasen'));
        if ($l['phasen'] !== null && $phasenUeblich !== null && (int)$l['phasen'] < (int)$phasenUeblich) {
            $melde('laden', 'warn', 'Nur ' . (int)$l['phasen'] . '-phasig geladen', 'Üblich ist ' . (int)$phasenUeblich
                   . '-phasig. Wallbox-Einstellung, Sicherung oder Kabel prüfen.');
        } else {
            $pNormal = median(array_column(array_filter($altL, fn($x) => $x['phasen'] === $l['phasen']), 'p_avg'));
            if ($pNormal && $l['p_avg'] && $l['p_avg'] < $pNormal * $S['lade_leistung_pct'] / 100) {
                $melde('laden', 'info', 'Langsamer geladen als üblich', 'Ø ' . zahl($l['p_avg'], 1) . ' kW statt üblich ' . zahl($pNormal, 1)
                       . ' kW (Ladestrom begrenzt? Akku kalt?).');
            }
        }
    }
    $ok('laden');
} else {
    $ok('laden', false);
}

// ── 6. Auto schläft nicht ───────────────────────────────────────────────────
$st = eine($db, "SELECT state, extract(epoch FROM (now() AT TIME ZONE 'UTC') - start_date) / 3600 AS stunden, start_date FROM states ORDER BY start_date DESC LIMIT 1");
if ($st) {
    if ($st['state'] === 'online' && $st['stunden'] >= $S['wach_std']) {
        $aktiv = eine($db, "SELECT (SELECT count(*) FROM drives WHERE end_date IS NULL OR end_date > \$1)
                                 + (SELECT count(*) FROM charging_processes WHERE end_date IS NULL OR end_date > \$1) AS n", [$st['start_date']]);
        if ($aktiv && (int)$aktiv['n'] === 0) {
            $melde('schlaf', 'warn', 'Auto schläft nicht', 'Seit ' . zahl($st['stunden'], 1)
                   . ' h wach, ohne zu fahren oder zu laden. Das kostet Akku (App offen gelassen, Wächtermodus, Drittanbieter-Abfragen?).');
        }
    }
    // Wachzeit ohne Fahren/Laden in 24 h
    $w = eine($db, "
        WITH f AS (SELECT (now() AT TIME ZONE 'UTC') - interval '24 hours' AS von, (now() AT TIME ZONE 'UTC') AS bis),
        wach AS (SELECT greatest(s.start_date, f.von) a, least(coalesce(s.end_date, f.bis), f.bis) e FROM states s, f
                 WHERE s.state = 'online' AND coalesce(s.end_date, f.bis) > f.von),
        aktiv AS (SELECT start_date a, coalesce(end_date, (SELECT bis FROM f)) e FROM drives
                  UNION ALL SELECT start_date, coalesce(end_date, (SELECT bis FROM f)) FROM charging_processes)
        SELECT sum(extract(epoch FROM w.e - w.a)) / 3600
             - coalesce((SELECT sum(extract(epoch FROM least(w2.e, x.e) - greatest(w2.a, x.a))) / 3600
                         FROM wach w2 JOIN aktiv x ON x.a < w2.e AND x.e > w2.a), 0) AS leer_h
        FROM wach w");
    if (!isset($geprueft['schlaf']) && $w && (float)$w['leer_h'] >= $S['wach_24h_std']) {
        $melde('schlaf', 'info', 'Auto oft wach', 'In den letzten 24 h ' . zahl($w['leer_h'], 1) . ' h wach, ohne zu fahren oder zu laden.');
    }
    $ok('schlaf');
} else {
    $ok('schlaf', false);
}

// ── 7. Wallbox-Anschluss (nur während des Ladens messbar) ───────────────────
$wb = DAHEIM_API !== '' ? http_json(rtrim(DAHEIM_API, '/') . '/api/combined') : null;
if ($wb && !empty($wb['wallbox']['ok']) && (float)($wb['wallbox']['power_w'] ?? 0) > 1000 && !empty($wb['cable_loss'])) {
    $k = $wb['cable_loss'];
    $dv = array_filter([1 => $k['delta_v_l1'] ?? null, 2 => $k['delta_v_l2'] ?? null, 3 => $k['delta_v_l3'] ?? null], fn($x) => $x !== null);
    $maxDv = $dv ? max($dv) : 0;
    if ((float)($k['loss_pct'] ?? 0) > $S['kabel_verlust_pct'] || $maxDv > $S['kabel_delta_v']) {
        $melde('wallbox', 'warn', 'Ungewöhnlicher Spannungsabfall an der Wallbox',
               'Kabelverlust ' . zahl($k['loss_pct'] ?? 0, 1) . ' %, bis ' . zahl($maxDv, 1) . ' V Abfall je Phase. Kann auf einen lockeren oder warmen Kontakt hindeuten. Anschlüsse von einer Elektrofachkraft prüfen lassen.');
    }
    $ok('wallbox');
} else {
    $ok('wallbox', false);   // nur beim Laden messbar
}

// ── 8. Akku niedrig ─────────────────────────────────────────────────────────
if (isset($mqtt['battery_level'])) {
    if ((float)$mqtt['battery_level'] < $S['akku_niedrig'] && ($mqtt['plugged_in'] ?? null) === false) {
        $melde('akku', 'warn', 'Akku niedrig', (int)$mqtt['battery_level'] . ' % und nicht angesteckt.');
    }
    $ok('akku');
} else {
    $ok('akku', false);
}

pg_close($db);
usort($hinweise, fn($a, $b) => $RANG[$b['stufe']] <=> $RANG[$a['stufe']]);
echo json_encode(['ok' => true, 'fenster_h' => $S['fenster_h'], 'hinweise' => $hinweise, 'geprueft' => $geprueft,
                  'erstellt' => date('c')], JSON_UNESCAPED_UNICODE);
