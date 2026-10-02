<?php
// Cloudflare-Access-Pflicht für alles, was über den Cloudflare-Tunnel kommt (z. B. https://tesla.example.com).
// Der Tunnel liefert lokal an (REMOTE_ADDR ::1/127.0.0.1). Solche Anfragen brauchen ein gültiges, von
// Cloudflare Access signiertes Token (Header Cf-Access-Jwt-Assertion oder Cookie CF_Authorization).
// Direkte Zugriffe aus LAN/Tailscale  prüft das nicht.
// Einstellungen: /etc/tesla-dashboard/cloudflare-access.php  (team, aud, optional emails)
// Fehlen team oder aud, ist der Weg über den Tunnel komplett zu.


// Als Tunnel gilt ein Zugriff von localhost nur, wenn Cloudflare Access eingerichtet ist (TD_CF_KONFIG vorhanden).
// Ohne Cloudflare ist localhost ein gewöhnlicher Zugriff, z. B. über einen Reverse-Proxy auf demselben Rechner.
function td_ueber_tunnel(): bool {
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['::1', '127.0.0.1'], true) && is_readable(TD_CF_KONFIG);
}

function td_cf_konfig(): array {
    $k = is_readable(TD_CF_KONFIG) ? include TD_CF_KONFIG : [];
    return is_array($k) ? $k : [];
}

function td_b64url(string $s): string|false {
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

// Öffentliche Schlüssel des Access-Teams (1 h zwischengespeichert), als [kid => PEM]
function td_cf_zertifikate(array $k, bool $neu = false): array {
    $team = $k['team'];
    $d = td_lesen('cf-certs.json');
    if (!$neu && ($d['team'] ?? '') === $team && ($d['geholt'] ?? 0) > time() - 3600 && !empty($d['certs'])) return $d['certs'];
    $url = $k['certs_url'] ?? "https://$team/cdn-cgi/access/certs";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$raw, true);
    $certs = [];
    foreach (($j['public_certs'] ?? []) as $c) if (!empty($c['kid']) && !empty($c['cert'])) $certs[$c['kid']] = $c['cert'];
    if ($certs) td_aendern('cf-certs.json', function (&$x) use ($team, $certs) { $x = ['team' => $team, 'geholt' => time(), 'certs' => $certs]; });
    return $certs ?: ($d['certs'] ?? []);
}

// Liefert die geprüften Angaben des Tokens oder null
function td_cf_pruefen(string $jwt, array $k): ?array {
    $teile = explode('.', $jwt);
    if (count($teile) !== 3) return null;
    [$h64, $p64, $s64] = $teile;
    $kopf = json_decode((string)td_b64url($h64), true);
    $daten = json_decode((string)td_b64url($p64), true);
    $sig = td_b64url($s64);
    if (!is_array($kopf) || !is_array($daten) || $sig === false || ($kopf['alg'] ?? '') !== 'RS256') return null;
    $kid = $kopf['kid'] ?? '';
    $certs = td_cf_zertifikate($k);
    if (!isset($certs[$kid])) $certs = td_cf_zertifikate($k, true);   // Schlüsselwechsel bei Cloudflare
    if (!isset($certs[$kid])) return null;
    if (openssl_verify("$h64.$p64", $sig, $certs[$kid], OPENSSL_ALGO_SHA256) !== 1) return null;

    $jetzt = time();
    $aud = (array)($daten['aud'] ?? []);
    if (!in_array($k['aud'], $aud, true)) return null;
    if (($daten['iss'] ?? '') !== 'https://' . $k['team']) return null;
    if (($daten['exp'] ?? 0) < $jetzt - 30 || ($daten['nbf'] ?? 0) > $jetzt + 30) return null;
    if (!empty($k['emails']) && !in_array(strtolower($daten['email'] ?? ''), array_map('strtolower', $k['emails']), true)) return null;
    return $daten;
}

// Am Anfang jeder PHP-Seite (über td_session_start): Tunnel ohne gültiges Access-Token → 403
function td_cloudflare_tor(): void {
    if (!td_ueber_tunnel()) return;
    $k   = td_cf_konfig();
    $jwt = $_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? ($_COOKIE['CF_Authorization'] ?? '');
    if (!empty($k['team']) && !empty($k['aud']) && is_string($jwt) && $jwt !== '' && td_cf_pruefen($jwt, $k) !== null) return;

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Zugang nur mit Cloudflare-Access-Anmeldung.\n";
    exit;
}
