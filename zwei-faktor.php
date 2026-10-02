<?php
// Authenticator-App koppeln (einmalig). Nur erreichbar direkt nach richtigem Passwort (login.php)
// und nur, solange noch keine App gekoppelt ist. Das Geheimnis wird erst nach einem gültigen Code gespeichert.
// Neu koppeln: sudo php tools/zugang.php totp-neu
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/sicherheit.php';

td_session_start();
if (td_angemeldet()) { header('Location: /index.php'); exit; }

$anm = $_SESSION['anmeldung'] ?? null;
if (!$anm || ($anm['schritt'] ?? '') !== 'koppeln' || ($anm['seit'] ?? 0) < time() - 600 || td_totp_eingerichtet()) {
    unset($_SESSION['anmeldung'], $_SESSION['totp_neu']);
    header('Location: /login.php');
    exit;
}

if (empty($_SESSION['totp_neu'])) $_SESSION['totp_neu'] = td_base32_encode(random_bytes(20));
$secret = $_SESSION['totp_neu'];
$fehler = '';
$sperre = td_gesperrt();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sperre === null) {
    if (!td_csrf_pruefen()) {
        $fehler = 'Sitzung abgelaufen – bitte erneut versuchen.';
    } elseif (td_totp_pruefen((string)($_POST['code'] ?? ''), $secret, false)) {
        $schritt = intdiv(time(), 30);
        td_aendern('zugang.json', function (&$z) use ($secret, $schritt) {
            $z['totp_secret'] = $secret; $z['totp_zuletzt'] = $schritt; $z['totp_gekoppelt'] = date('c');
        });
        td_protokoll('totp_gekoppelt');
        td_anmelden(false);
        header('Location: /index.php');
        exit;
    } else {
        td_fehlversuch('koppeln');
        $fehler = 'Der Code passt nicht. Uhrzeit am Handy prüfen und den aktuellen Code eingeben.';
    }
    $sperre = td_gesperrt();
}

$uri = 'otpauth://totp/' . rawurlencode('Tesla Dashboard:' . FAHRZEUG_NAME) . '?secret=' . $secret
     . '&issuer=' . rawurlencode('Tesla Dashboard') . '&algorithm=SHA1&digits=6&period=30';
$csrf = td_csrf_token();
http_response_code($sperre !== null ? 403 : 200);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tesla Dashboard – Authenticator koppeln</title>
<link rel="icon" href="/assets/icons/favicon.svg?v=2" type="image/svg+xml" id="favicon">
<link rel="alternate icon" href="/favicon.ico?v=2" sizes="16x16 32x32 48x48">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
<link rel="manifest" href="/manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#0a0a14">
<meta name="apple-mobile-web-app-title" content="Tesla">
<meta name="mobile-web-app-capable" content="yes">
<link rel="stylesheet" href="/assets/login.css?v=2">
<!-- QR-Code-Bibliothek lokal: qrcode-generator 1.4.4 (Kazuhiko Arase, MIT), aus dem npm-Paket, Prüfsumme gegen die npm-Registry geprüft am 26.09.2026 -->
<script src="/assets/qrcode.js?v=1.4.4"></script>
</head>
<body>
<div class="login-wrap">
  <div class="card">
    <div class="brand">
      <h1>Authenticator koppeln</h1>
      <p>Einmalig – danach fragt der Login nach dem 6-stelligen Code</p>
    </div>

    <?php if ($sperre !== null): ?>
      <div class="alert alert-error">Zu viele Fehlversuche. Bitte in <?= (int)ceil($sperre / 60) ?> Minuten erneut anmelden.</div>
    <?php else: ?>
      <?php if ($fehler): ?><div class="alert alert-error"><?= htmlspecialchars($fehler) ?></div><?php endif; ?>
      <p class="hinweis">1. In der Authenticator-App (z. B. Google/Microsoft Authenticator, 2FAS, Bitwarden) ein neues Konto hinzufügen und diesen QR-Code scannen:</p>
      <div class="qr-box" id="qr"></div>
      <p class="hinweis">Oder den Schlüssel von Hand eingeben (zeitbasiert, 6 Stellen, 30 s):</p>
      <div class="secret"><?= htmlspecialchars(trim(chunk_split($secret, 4, ' '))) ?></div>
      <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <div class="form-group">
          <label for="code">2. Angezeigten Code eingeben</label>
          <input class="code" type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
                 pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" autofocus required>
        </div>
        <button type="submit" class="btn">Koppeln &amp; anmelden</button>
      </form>
      <script>
        (function() {
          if (typeof qrcode !== 'function') { document.getElementById('qr').style.display = 'none'; return; }
          var qr = qrcode(0, 'M');
          qr.addData(<?= json_encode($uri, JSON_UNESCAPED_SLASHES) ?>);
          qr.make();
          document.getElementById('qr').innerHTML = qr.createSvgTag({cellSize: 5, margin: 2, scalable: true});
        })();
      </script>
    <?php endif; ?>

    <p class="footer-note"><a href="/login.php?neu=1" style="color:inherit">Abbrechen</a></p>
  </div>
</div>
</body>
</html>
