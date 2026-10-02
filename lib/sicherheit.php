<?php
// Login, Zwei-Faktor (TOTP), Sperren, CSRF und Befehlsprotokoll für das Tesla-Dashboard.
// Veränderliche Daten liegen außerhalb des Web-Ordners in /var/lib/tesla-dashboard (www-data, 700):
//   zugang.json   – Passwort-Hash und TOTP-Geheimnis
//   sperren.json  – Fehlversuche je IP und gesamt
//   cf-certs.json – zwischengespeicherte Schlüssel von Cloudflare Access (lib/cloudflare.php)
//   protokoll.log – eine JSON-Zeile je Anmeldung/Befehl
// Einrichtung/Notfall: sudo php tools/zugang.php

// Pfade lassen sich für Tests vorab per define() überschreiben
if (!defined('TD_SCHLUESSEL')) define('TD_SCHLUESSEL', '/etc/tesla-dashboard/app.key');   // root:www-data 640, 32 Byte zufällig
require_once __DIR__ . '/cloudflare.php';   // Access-Pflicht für den Weg über den Cloudflare-Tunnel

const TD_IP_VERSUCHE  = 5;      // Fehlversuche je IP …
const TD_IP_SPERRE    = 900;    // … dann 15 min gesperrt
const TD_GESAMT_VERSUCHE = 30;  // Fehlversuche aller IPs innerhalb einer Stunde …
const TD_GESAMT_SPERRE   = 1800; // … dann 30 min für alle gesperrt
const TD_STEPUP_GUELTIG  = 300; // ein frischer TOTP-Code gilt 5 min für kritische Befehle
// Heimnetz: nur Passwort, Sitzung 1 Woche (Wunsch 26.09.2026). „Lokal“ zählt nur die echte Verbindungsadresse,
// nie ein Header; Anfragen über den Cloudflare-Tunnel sind nie lokal. Tailscale über den Subnet-Router
// kommt mit einer Heimnetz-Adresse an und zählt damit ebenfalls als lokal.
const TD_LOKAL_TIMEOUT = 604800;  // 7 Tage
// CLOUDFLARE_REICHT (config.local.php): über den Cloudflare-Tunnel reicht dann die Access-Anmeldung, kein eigenes Passwort.
// td_cloudflare_tor() prüft vor jeder Seite Signatur, Team, Anwendung (aud), Ablauf und die zugelassene E-Mail.

// ── JSON-Dateien mit Dateisperre ─────────────────────────────────────────

function td_lesen(string $name): array {
    $f = TD_DATEN . '/' . $name;
    if (!is_file($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

// Liest, verändert und schreibt eine Datei unter exklusiver Sperre
function td_aendern(string $name, callable $fn) {
    $f  = TD_DATEN . '/' . $name;
    $fh = fopen($f, 'c+');
    if (!$fh) throw new RuntimeException("Kann $f nicht öffnen");
    flock($fh, LOCK_EX);
    try {
        $raw  = stream_get_contents($fh);
        $data = json_decode($raw ?: '[]', true);
        if (!is_array($data)) $data = [];
        $ret  = $fn($data);          // wirft die Funktion, bleibt die Datei unverändert
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    @chmod($f, 0600);
    return $ret;
}

// ── Client-IP ────────────────────────────────────────────────────────────
// Über den lokalen Cloudflare-Tunnel sieht Apache nur ::1/127.0.0.1; dann gilt CF-Connecting-IP.
function td_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (td_ueber_tunnel() && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {   // nur hinter dem Tunnel glauben
        $cf = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
    }
    return $remote ?: 'unbekannt';
}

function td_ip_im_netz(string $ip, string $cidr): bool {
    [$netz, $bits] = explode('/', $cidr) + [1 => '32'];
    $a = ip2long($ip); $n = ip2long($netz);
    if ($a === false || $n === false) return false;
    $maske = $bits == 0 ? 0 : (-1 << (32 - (int)$bits)) & 0xFFFFFFFF;
    return ($a & $maske) === ($n & $maske);
}

// Direkter Zugriff aus dem Heimnetz (nicht über den Tunnel, Adresse in LOKALE_NETZE)
function td_lokal(): bool {
    if (td_ueber_tunnel()) return false;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    foreach (LOKALE_NETZE as $netz) if (td_ip_im_netz($ip, $netz)) return true;
    return false;
}

// ── Protokoll ────────────────────────────────────────────────────────────

function td_protokoll(string $art, array $daten = []): void {
    $zeile = json_encode(['zeit' => date('c'), 'ip' => td_ip(), 'art' => $art] + $daten,
                         JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents(TD_DATEN . '/protokoll.log', $zeile . "\n", FILE_APPEND | LOCK_EX);
}

function td_protokoll_letzte(int $n = 50, ?string $art = null): array {
    $f = TD_DATEN . '/protokoll.log';
    if (!is_file($f)) return [];
    $zeilen = array_reverse(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    $out = [];
    foreach ($zeilen as $z) {
        $d = json_decode($z, true);
        if (!$d || ($art !== null && ($d['art'] ?? '') !== $art)) continue;
        $out[] = $d;
        if (count($out) >= $n) break;
    }
    return $out;
}

// ── Sperren ──────────────────────────────────────────────────────────────

// Liefert null, wenn Anmeldeversuche erlaubt sind, sonst die verbleibenden Sekunden der Sperre
function td_gesperrt(): ?int {
    $d = td_lesen('sperren.json');
    $jetzt = time();
    $ip = td_ip();
    $bis = max((int)($d['_gesamt']['bis'] ?? 0), (int)($d['ip'][$ip]['bis'] ?? 0));
    return $bis > $jetzt ? $bis - $jetzt : null;
}

function td_fehlversuch(string $grund): void {
    $ip = td_ip();
    td_aendern('sperren.json', function (&$d) use ($ip) {
        $jetzt = time();
        $e = $d['ip'][$ip] ?? ['anzahl' => 0, 'bis' => 0];
        if ($e['bis'] && $e['bis'] <= $jetzt) $e = ['anzahl' => 0, 'bis' => 0];   // alte Sperre abgelaufen
        $e['anzahl']++;
        $e['zuletzt'] = $jetzt;
        if ($e['anzahl'] >= TD_IP_VERSUCHE) { $e['bis'] = $jetzt + TD_IP_SPERRE; $e['anzahl'] = 0; }
        $d['ip'][$ip] = $e;

        $g = $d['_gesamt'] ?? ['anzahl' => 0, 'seit' => $jetzt, 'bis' => 0];
        if ($jetzt - ($g['seit'] ?? 0) > 3600) $g = ['anzahl' => 0, 'seit' => $jetzt, 'bis' => 0];
        $g['anzahl']++;
        if ($g['anzahl'] >= TD_GESAMT_VERSUCHE) { $g['bis'] = $jetzt + TD_GESAMT_SPERRE; $g['anzahl'] = 0; $g['seit'] = $jetzt; }
        $d['_gesamt'] = $g;

        // Einträge älter als ein Tag aufräumen
        foreach ($d['ip'] as $k => $v) {
            if (($v['bis'] ?? 0) < $jetzt && ($v['zuletzt'] ?? 0) < $jetzt - 86400) unset($d['ip'][$k]);
        }
    });
    td_protokoll('fehlversuch', ['grund' => $grund]);
}

function td_erfolg(): void {
    $ip = td_ip();
    td_aendern('sperren.json', function (&$d) use ($ip) { unset($d['ip'][$ip]); });
}

// ── Passwort & TOTP ──────────────────────────────────────────────────────

function td_zugang(): array { return td_lesen('zugang.json'); }

function td_passwort_ok(string $pw): bool {
    $h = td_zugang()['passwort_hash'] ?? '';
    // Auch ohne eingerichtetes Passwort gleich lange rechnen (kein Timing-Hinweis)
    if ($h === '') { password_hash($pw, PASSWORD_ARGON2ID); return false; }
    return password_verify($pw, $h);
}

function td_base32_decode(string $s): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    $bits = '';
    foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
    return $out;
}

function td_base32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $b) $out .= $alpha[bindec(str_pad($b, 5, '0'))];
    return $out;
}

function td_totp(string $secretB32, int $schritt): string {
    $key = td_base32_decode($secretB32);
    $h = hash_hmac('sha1', pack('J', $schritt), $key, true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

// Prüft einen 6-stelligen Code (±30 s Toleranz) und verhindert, dass derselbe Code zweimal gilt
function td_totp_pruefen(string $code, ?string $secret = null, bool $merken = true): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $secret = $secret ?? (td_zugang()['totp_secret'] ?? '');
    if ($secret === '') return false;
    $jetzt = intdiv(time(), 30);
    $zuletzt = (int)(td_zugang()['totp_zuletzt'] ?? 0);
    foreach ([0, -1, 1] as $d) {
        $s = $jetzt + $d;
        if ($s <= $zuletzt) continue;
        if (hash_equals(td_totp($secret, $s), $code)) {
            if ($merken) td_aendern('zugang.json', function (&$z) use ($s) { $z['totp_zuletzt'] = $s; });
            return true;
        }
    }
    return false;
}

function td_totp_eingerichtet(): bool { return (td_zugang()['totp_secret'] ?? '') !== ''; }

// ── Session ──────────────────────────────────────────────────────────────

function td_cookie_optionen(int $ablauf = 0): array {
    // Cookie nur über HTTPS; TD_TEST_HTTP setzt ausschließlich der Test-Vorspann (lokaler PHP-Testserver)
    return ['expires' => $ablauf, 'path' => '/', 'secure' => NUR_HTTPS && !defined('TD_TEST_HTTP'), 'httponly' => true, 'samesite' => 'Strict'];
}

function td_session_start(): void {
    td_cloudflare_tor();
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // Eigene Ablage: Debians phpsessionclean löscht /var/lib/php/sessions schon nach 24 min Inaktivität
    $ablage = TD_DATEN . '/sitzungen';
    if (!is_dir($ablage)) @mkdir($ablage, 0700);
    session_save_path($ablage);
    ini_set('session.gc_maxlifetime', (string)(TD_LOKAL_TIMEOUT + 86400));
    ini_set('session.gc_probability', '0');
    if (random_int(1, 200) === 1) {   // selten selbst aufräumen: älter als 8 Tage
        foreach (glob($ablage . '/sess_*') ?: [] as $f) if (@filemtime($f) < time() - TD_LOKAL_TIMEOUT - 86400) @unlink($f);
    }
    $o = td_cookie_optionen(); unset($o['expires']);
    session_set_cookie_params(['lifetime' => 0] + $o);
    session_start();
}

function td_timeout(): int { return !empty($_SESSION['lokal']) ? TD_LOKAL_TIMEOUT : SESSION_TIMEOUT; }

function td_angemeldet(): bool {
    return ($_SESSION['authenticated'] ?? false) === true
        && (time() - ($_SESSION['login_time'] ?? 0)) <= td_timeout()
        && ($_SESSION['ip'] ?? '') === td_ip()
        && (empty($_SESSION['lokal']) || td_lokal());   // lokale Sitzung gilt nur im Heimnetz
}

// Anmeldung abschließen (nach Passwort im Heimnetz bzw. nach Passwort + Code von außen)
function td_anmelden(bool $lokal, array $protokoll = []): void {
    session_regenerate_id(true);
    unset($_SESSION['anmeldung'], $_SESSION['totp_neu'], $_SESSION['csrf']);
    $_SESSION['authenticated'] = true;
    $_SESSION['login_time']    = time();
    $_SESSION['ip']            = td_ip();
    $_SESSION['lokal']         = $lokal;
    if ($lokal) td_cookie_verlaengern();
    td_erfolg();
    td_protokoll('login', ['lokal' => $lokal] + $protokoll);
}

// Über den Tunnel mit gültiger Cloudflare-Anmeldung automatisch anmelden (siehe CLOUDFLARE_REICHT).
// td_session_start() → td_cloudflare_tor() hat das Token hier schon geprüft; ohne gültiges Token kommt man gar nicht bis hierher.
function td_cloudflare_anmeldung(): bool {
    if (!CLOUDFLARE_REICHT || !td_ueber_tunnel()) return false;
    $k = td_cf_konfig();
    $jwt = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? ($_COOKIE['CF_Authorization'] ?? '');
    $daten = (is_string($jwt) && $jwt !== '' && !empty($k['team']) && !empty($k['aud'])) ? td_cf_pruefen($jwt, $k) : null;
    if ($daten === null) return false;   // doppelt hält besser
    td_anmelden(false, ['quelle' => 'cloudflare', 'email' => strtolower((string)($daten['email'] ?? '')),
                        'land' => (string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')]);
    return true;
}

// Lokale Sitzung: Cookie überlebt das Schließen des Browsers, läuft 7 Tage nach der letzten Nutzung ab
function td_cookie_verlaengern(): void {
    if (headers_sent()) return;
    setcookie(session_name(), session_id(), td_cookie_optionen(time() + TD_LOKAL_TIMEOUT));
}

// Nur eine abgelaufene/ungültige Anmeldung verwerfen – ein laufender Login (Passwort-Schritt, CSRF) bleibt erhalten
function td_anmeldung_verwerfen(): void {
    if (!empty($_SESSION['authenticated'])) { $_SESSION = []; session_regenerate_id(true); }
}

function td_seite_schuetzen(): void {
    td_session_start();
    if (!td_angemeldet() && !td_cloudflare_anmeldung()) { td_anmeldung_verwerfen(); header('Location: /login.php'); exit; }
    $_SESSION['login_time'] = time();
    if (!empty($_SESSION['lokal'])) td_cookie_verlaengern();
}

function td_api_schuetzen(): void {
    td_session_start();
    if (!td_angemeldet() && !td_cloudflare_anmeldung()) {
        td_anmeldung_verwerfen();
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Nicht authentifiziert']);
        exit;
    }
    $_SESSION['login_time'] = time();
}

function td_csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

// Für alle schreibenden Anfragen: gleicher Ursprung + gültiges CSRF-Token
function td_csrf_pruefen(): bool {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $o = parse_url($origin);
        $herkunft = ($o['host'] ?? '') . (isset($o['port']) ? ':' . $o['port'] : '');   // wie HTTP_HOST: Host[:Port]
        if (strcasecmp($herkunft, $_SERVER['HTTP_HOST'] ?? '') !== 0) return false;
    }
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

// Frischer TOTP-Code für kritische Aktionen; gilt danach TD_STEPUP_GUELTIG Sekunden
function td_stepup(?string $code): bool {
    if (($_SESSION['stepup_bis'] ?? 0) > time()) return true;
    if ($code === null || $code === '') return false;
    if (td_gesperrt() !== null) return false;
    if (!td_totp_pruefen($code)) { td_fehlversuch('stepup'); return false; }
    $_SESSION['stepup_bis'] = time() + TD_STEPUP_GUELTIG;
    return true;
}

// ── Verschlüsselung ruhender Daten (Tesla-Tokens) ────────────────────────

function td_app_key(): string {
    $k = @file_get_contents(TD_SCHLUESSEL);
    if ($k === false || strlen(trim($k)) !== 64) throw new RuntimeException('App-Schlüssel fehlt – tools/zugang.php einrichten ausführen');
    return sodium_hex2bin(trim($k));
}

function td_verschluesseln(string $klar): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($klar, $nonce, td_app_key()));
}

function td_entschluesseln(string $b64): ?string {
    $bin = base64_decode($b64, true);
    if ($bin === false || strlen($bin) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 16) return null;
    $klar = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
                                         substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), td_app_key());
    return $klar === false ? null : $klar;
}
