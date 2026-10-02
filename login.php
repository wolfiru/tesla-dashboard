<?php
// Anmeldung per Passwort. Der zweite Faktor (2FA) kommt von Cloudflare Access; der frühere Authenticator-Schritt ist entfernt.
// Fehlversuche werden serverseitig je IP und insgesamt gezählt (lib/sicherheit.php).
// Passwort setzen: sudo php tools/zugang.php passwort
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/sicherheit.php';

td_session_start();
if (td_angemeldet() || td_cloudflare_anmeldung()) { header('Location: /index.php'); exit; }   // über Cloudflare: kein Passwort nötig

const TD_ANMELDUNG_GUELTIG = 600;   // Passwort-Schritt gilt 10 min für den Code-Schritt

$fehler  = '';
$hinweis = '';
$pwGesetzt   = !empty(td_zugang()['passwort_hash']);
$totpGesetzt = td_totp_eingerichtet();

if (isset($_GET['neu'])) unset($_SESSION['anmeldung'], $_SESSION['totp_neu']);
$anm = $_SESSION['anmeldung'] ?? null;
if ($anm && ($anm['seit'] ?? 0) < time() - TD_ANMELDUNG_GUELTIG) { unset($_SESSION['anmeldung']); $anm = null; }
$schritt = $anm['schritt'] ?? 'passwort';

$sperre = td_gesperrt();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pwGesetzt && $sperre === null) {
    if (!td_csrf_pruefen()) {
        $fehler = 'Sitzung abgelaufen – bitte erneut versuchen.';
    } elseif (($_POST['aktion'] ?? '') === 'abbrechen') {
        unset($_SESSION['anmeldung']);
        $schritt = 'passwort';
    } elseif ($schritt === 'passwort') {
        if (td_passwort_ok((string)($_POST['passwort'] ?? ''))) {
            // Zweiter Faktor entfällt: Cloudflare Access sitzt vor der Seite
            td_anmelden(td_lokal());   // Heimnetz: Sitzung 1 Woche, von außen normale Sitzung
            header('Location: /index.php');
            exit;
        } else {
            td_fehlversuch('passwort');
            $fehler = 'Passwort falsch.';
        }
    } elseif ($schritt === 'code') {
        if (td_totp_pruefen((string)($_POST['code'] ?? ''))) {
            td_anmelden(false);
            header('Location: /index.php');
            exit;
        }
        td_fehlversuch('code');
        $fehler = 'Code falsch oder schon verwendet – bitte den aktuellen Code aus der App eingeben.';
    }
    $sperre = td_gesperrt();
}

if ($schritt === 'koppeln' && !$totpGesetzt) { header('Location: /zwei-faktor.php'); exit; }

// Verbleibende Versuche für die Warnung
$ip = td_ip();
$versuche = (int)(td_lesen('sperren.json')['ip'][$ip]['anzahl'] ?? 0);
if (!$fehler && $versuche >= 3 && $sperre === null) {
    $rest = TD_IP_VERSUCHE - $versuche;
    $hinweis = "Noch $rest Versuch" . ($rest === 1 ? '' : 'e') . ', dann wird der Zugang für 15 Minuten gesperrt.';
}

http_response_code($sperre !== null ? 403 : 200);
$csrf = td_csrf_token();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tesla Dashboard – Login</title>
<link rel="icon" href="/assets/icons/favicon.svg?v=2" type="image/svg+xml" id="favicon">
<link rel="alternate icon" href="/favicon.ico?v=2" sizes="16x16 32x32 48x48">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png?v=2">
<link rel="manifest" href="/manifest.webmanifest" crossorigin="use-credentials">
<meta name="theme-color" content="#0a0a14">
<meta name="apple-mobile-web-app-title" content="Tesla">
<meta name="mobile-web-app-capable" content="yes">
<link rel="stylesheet" href="/assets/login.css?v=3">
</head>
<body>
<div class="login-wrap">
  <div class="card">
    <div class="brand">
      <div class="brand-logo">
        <img src="/assets/icons/icon-voll.svg?v=2" alt="">
      </div>
      <h1>Tesla Dashboard</h1>
      <p>Telemetrie &amp; Wallbox Monitor</p>
    </div>

    <?php if (isset($_GET['logout'])): ?>
      <div class="alert alert-info">Erfolgreich abgemeldet.</div>
    <?php endif; ?>

    <?php if (!$pwGesetzt): ?>
      <div class="alert alert-warning">Es ist noch kein Passwort eingerichtet.</div>
      <p class="hinweis">Auf dem Server im Dashboard-Ordner ausführen:<br>
        <code>sudo php tools/zugang.php passwort</code></p>

    <?php elseif ($sperre !== null): ?>
      <div class="alert alert-error">Zu viele Fehlversuche. Zugang gesperrt.</div>
      <div class="countdown-wrap">
        <div class="countdown-display" id="countdown">--:--</div>
        <div class="countdown-label">Verbleibende Sperrzeit</div>
      </div>
      <script>
        (function() {
          var remaining = <?= (int)$sperre ?>;
          function fmt(s) { var m = Math.floor(s / 60), sec = s % 60; return String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0'); }
          var el = document.getElementById('countdown');
          el.textContent = fmt(remaining);
          var iv = setInterval(function() {
            remaining--;
            if (remaining <= 0) { clearInterval(iv); location.reload(); } else { el.textContent = fmt(remaining); }
          }, 1000);
        })();
      </script>

    <?php else: ?>
      <?php if ($fehler): ?><div class="alert alert-error"><?= htmlspecialchars($fehler) ?></div><?php endif; ?>
      <?php if ($hinweis): ?><div class="alert alert-warning"><?= htmlspecialchars($hinweis) ?></div><?php endif; ?>

      <?php if ($schritt === 'code'): ?>
        <div class="schritt">Schritt 2 von 2</div>
        <form method="POST" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <div class="form-group">
            <label for="code">Code aus der Authenticator-App</label>
            <input class="code" type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" autofocus required>
          </div>
          <button type="submit" class="btn">Anmelden</button>
        </form>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <button class="link-btn" type="submit" name="aktion" value="abbrechen">Abbrechen</button>
        </form>
      <?php else: ?>
        <div class="schritt"><?= td_lokal() ? 'Heimnetz: Passwort genügt' : 'Passwort' ?></div>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
          <div class="form-group">
            <label for="passwort">Passwort</label>
            <input class="pw" type="password" id="passwort" name="passwort" autocomplete="current-password" autofocus required>
          </div>
          <button type="submit" class="btn">Weiter</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>

    <p class="footer-note"><?= htmlspecialchars(FAHRZEUG_NAME) ?> &bull; Daten aus TeslaMate</p>
  </div>
</div>
</body>
</html>
