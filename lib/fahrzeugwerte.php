<?php
// Aktuelle Fahrzeugwerte, die TeslaMate per MQTT veröffentlicht (Verriegelung, Türen, Wächtermodus, Ladeziel …).
// Zwei Quellen, Auswahl in config.local.php über ZUSTAND_QUELLE:
//   'mqtt'     – direkt beim MQTT-Broker von TeslaMate (z. B. Mosquitto); TeslaMate sendet seine Werte „retained“,
//                darum reicht kurzes Verbinden, Abonnieren und Einsammeln. Kein Zusatzpaket nötig.
//   'iobroker' – über den REST-Adapter von ioBroker, falls TeslaMate dort hinein veröffentlicht (mqtt.0.teslamate.cars.N.*)
//   'keine'    – Karte „Fahrzeugzustand“ und die MQTT-Prüfungen der Auffälligkeiten entfallen.
// Ergebnis: ['werte' => [schlüssel => wert], 'zeit' => [schlüssel => unix-zeit oder null]] oder null bei Fehler.

function td_wert_normalisieren($v) {
    if (!is_string($v)) return $v;
    $l = strtolower(trim($v));
    if ($l === 'true') return true;
    if ($l === 'false') return false;
    if ($l === 'none' || $l === 'nil' || $l === 'null' || $l === '') return null;
    return is_numeric($v) ? $v + 0 : $v;
}

function td_fahrzeugwerte(): ?array {
    static $cache = null;
    if ($cache !== null) return $cache ?: null;
    $quelle = defined('ZUSTAND_QUELLE') ? ZUSTAND_QUELLE : 'keine';
    $erg = null;
    if ($quelle === 'iobroker') $erg = td_werte_iobroker();
    elseif ($quelle === 'mqtt') $erg = td_werte_mqtt();
    $cache = $erg ?? [];
    return $erg;
}

function td_werte_iobroker(): ?array {
    $prefix = 'mqtt.0.teslamate.cars.' . (defined('TESLAMATE_CAR_ID') ? TESLAMATE_CAR_ID : 1) . '.';
    $ch = curl_init(rtrim(IOBROKER_REST, '/') . '/v1/states?filter=' . rawurlencode($prefix . '*'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $liste = json_decode((string)$raw, true);
    if ($code !== 200 || !is_array($liste)) return null;
    $w = []; $z = [];
    foreach ($liste as $id => $s) {
        if (!is_array($s) || !str_starts_with((string)$id, $prefix)) continue;
        $k = substr($id, strlen($prefix));
        $w[$k] = td_wert_normalisieren($s['val'] ?? null);
        $z[$k] = isset($s['ts']) ? (int)($s['ts'] / 1000) : null;
    }
    return ['werte' => $w, 'zeit' => $z];
}

// ── Minimaler MQTT-3.1.1-Client (nur abonnieren, QoS 0) ──────────────────
function td_mqtt_str(string $s): string { return pack('n', strlen($s)) . $s; }

function td_mqtt_laenge(int $n): string {   // „Remaining Length“, variabel kodiert
    $out = '';
    do { $b = $n % 128; $n = intdiv($n, 128); if ($n > 0) $b |= 0x80; $out .= chr($b); } while ($n > 0);
    return $out;
}

function td_mqtt_paket($sock, float $bis): ?array {   // liest ein Paket: [typ, nutzdaten] oder null (Zeit um / Ende)
    $lesen = function (int $n) use ($sock, $bis): ?string {
        $buf = '';
        while (strlen($buf) < $n) {
            $rest = $bis - microtime(true);
            if ($rest <= 0) return null;
            $r = [$sock]; $w = $e = null;
            if (@stream_select($r, $w, $e, 0, (int)min($rest * 1e6, 999999)) < 1) continue;
            $teil = fread($sock, $n - strlen($buf));
            if ($teil === '' || $teil === false) return null;
            $buf .= $teil;
        }
        return $buf;
    };
    $kopf = $lesen(1);
    if ($kopf === null) return null;
    $laenge = 0; $faktor = 1;
    for ($i = 0; $i < 4; $i++) {
        $b = $lesen(1);
        if ($b === null) return null;
        $laenge += (ord($b) & 0x7F) * $faktor; $faktor *= 128;
        if (!(ord($b) & 0x80)) break;
    }
    $daten = $laenge ? $lesen($laenge) : '';
    return $daten === null ? null : [ord($kopf) >> 4, ord($kopf) & 0x0F, $daten];
}

function td_werte_mqtt(): ?array {
    $host = defined('MQTT_HOST') ? MQTT_HOST : '127.0.0.1';
    $port = defined('MQTT_PORT') ? MQTT_PORT : 1883;
    $tls  = defined('MQTT_TLS') && MQTT_TLS;
    $basis = trim(defined('MQTT_NAMESPACE') && MQTT_NAMESPACE !== '' ? MQTT_NAMESPACE . '/teslamate' : 'teslamate', '/')
           . '/cars/' . (defined('TESLAMATE_CAR_ID') ? TESLAMATE_CAR_ID : 1) . '/';
    $sock = @stream_socket_client(($tls ? 'tls://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 2);
    if (!$sock) return null;
    stream_set_blocking($sock, true);
    $user = defined('MQTT_USER') ? MQTT_USER : '';
    $pass = defined('MQTT_PASS') ? MQTT_PASS : '';
    $flags = 0x02 | ($user !== '' ? 0x80 : 0) | ($pass !== '' ? 0x40 : 0);   // clean session (+ Benutzer/Passwort)
    $var = td_mqtt_str('MQTT') . chr(4) . chr($flags) . pack('n', 30);
    $nutz = td_mqtt_str('tesla-dashboard-' . bin2hex(random_bytes(4)))
          . ($user !== '' ? td_mqtt_str($user) : '') . ($pass !== '' ? td_mqtt_str($pass) : '');
    fwrite($sock, chr(0x10) . td_mqtt_laenge(strlen($var . $nutz)) . $var . $nutz);
    $ack = td_mqtt_paket($sock, microtime(true) + 3);
    if (!$ack || $ack[0] !== 2 || strlen($ack[2]) < 2 || ord($ack[2][1]) !== 0) { fclose($sock); return null; }
    $sub = pack('n', 1) . td_mqtt_str($basis . '#') . chr(0);
    fwrite($sock, chr(0x82) . td_mqtt_laenge(strlen($sub)) . $sub);
    $w = []; $z = [];
    $ende = microtime(true) + 3.0;          // höchstens 3 s …
    $ruhe = microtime(true) + 1.0;          // … und schluss, sobald 0,4 s nichts mehr kommt (retained Werte kommen sofort)
    while (microtime(true) < $ende) {
        $p = td_mqtt_paket($sock, min($ende, $ruhe));
        if ($p === null) break;
        if ($p[0] !== 3) continue;           // nur PUBLISH
        $d = $p[2];
        $tl = unpack('n', substr($d, 0, 2))[1];
        $topic = substr($d, 2, $tl);
        $off = 2 + $tl + ((($p[1] >> 1) & 3) > 0 ? 2 : 0);   // bei QoS > 0 folgt eine Paket-ID
        if (str_starts_with($topic, $basis)) {
            $k = substr($topic, strlen($basis));
            $w[$k] = td_wert_normalisieren(substr($d, $off));
            $z[$k] = null;                    // retained Nachrichten tragen keine Zeit
        }
        $ruhe = microtime(true) + 0.4;
    }
    @fwrite($sock, chr(0xE0) . chr(0));
    fclose($sock);
    return $w ? ['werte' => $w, 'zeit' => $z] : null;
}
