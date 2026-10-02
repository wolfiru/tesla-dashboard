<?php
// Zugang zum Tesla-Dashboard verwalten – nur auf der Kommandozeile des Servers (im Dashboard-Ordner):
//   sudo php tools/zugang.php einrichten   Ordner + App-Schlüssel anlegen
//   sudo php tools/zugang.php passwort     Login-Passwort setzen (verdeckte Eingabe)
//   sudo php tools/zugang.php totp-neu     Authenticator neu koppeln (beim nächsten Login)
//   sudo php tools/zugang.php entsperren   alle Login-Sperren aufheben
//   sudo php tools/zugang.php status
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (posix_geteuid() !== 0) { fwrite(STDERR, "Bitte mit sudo ausführen.\n"); exit(1); }

require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/lib/sicherheit.php';

function besitz(string $pfad, string $user, string $gruppe, int $modus): void {
    chown($pfad, $user); chgrp($pfad, $gruppe); chmod($pfad, $modus);
}

function einrichten(): void {
    if (!is_dir(TD_DATEN)) mkdir(TD_DATEN, 0700, true);
    besitz(TD_DATEN, 'www-data', 'www-data', 0700);
    $dir = dirname(TD_SCHLUESSEL);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    besitz($dir, 'root', 'www-data', 0750);
    if (!is_file(TD_SCHLUESSEL)) {
        file_put_contents(TD_SCHLUESSEL, bin2hex(random_bytes(32)) . "\n");
        echo "App-Schlüssel erzeugt.\n";
    }
    besitz(TD_SCHLUESSEL, 'root', 'www-data', 0640);
    foreach (['zugang.json', 'sperren.json', 'protokoll.log'] as $f) {
        $p = TD_DATEN . "/$f";
        if (!is_file($p)) file_put_contents($p, str_ends_with($f, '.json') ? '{}' : '');
        besitz($p, 'www-data', 'www-data', 0600);
    }
    echo "Eingerichtet: " . TD_DATEN . " und " . TD_SCHLUESSEL . "\n";
}

function verdeckt(string $frage): string {
    echo $frage;
    system('stty -echo');
    $w = rtrim((string)fgets(STDIN), "\r\n");
    system('stty echo');
    echo "\n";
    return $w;
}

$cmd = $argv[1] ?? 'status';
switch ($cmd) {
    case 'einrichten':
        einrichten();
        break;

    case 'passwort':
        einrichten();
        if (($argv[2] ?? '') === '--stdin') {   // vom Installer: eine Zeile von der Standardeingabe (nicht in der Prozessliste)
            $a = rtrim((string)fgets(STDIN), "\r\n");
            if (mb_strlen($a) < 12) { fwrite(STDERR, "Zu kurz.\n"); exit(1); }
        } else {
            $a = verdeckt('Neues Passwort (mind. 12 Zeichen): ');
            if (mb_strlen($a) < 12) { fwrite(STDERR, "Zu kurz.\n"); exit(1); }
            if (verdeckt('Wiederholen: ') !== $a) { fwrite(STDERR, "Stimmt nicht überein.\n"); exit(1); }
        }
        td_aendern('zugang.json', function (&$z) use ($a) { $z['passwort_hash'] = password_hash($a, PASSWORD_ARGON2ID); $z['passwort_geaendert'] = date('c'); });
        besitz(TD_DATEN . '/zugang.json', 'www-data', 'www-data', 0600);
        td_protokoll('passwort_gesetzt', ['quelle' => 'cli']);
        echo "Passwort gesetzt." . (td_totp_eingerichtet() ? "\n" : " Beim nächsten Login wird der Authenticator gekoppelt.\n");
        break;

    case 'totp-neu':
        td_aendern('zugang.json', function (&$z) { unset($z['totp_secret'], $z['totp_zuletzt']); });
        td_protokoll('totp_zurueckgesetzt', ['quelle' => 'cli']);
        echo "Authenticator entkoppelt. Beim nächsten Login mit Passwort wird neu gekoppelt.\n";
        break;

    case 'entsperren':
        td_aendern('sperren.json', function (&$d) { $d = []; });
        echo "Alle Sperren aufgehoben.\n";
        break;

    case 'status':
        $z = td_zugang(); $s = td_lesen('sperren.json');
        echo "Passwort:      " . (!empty($z['passwort_hash']) ? 'gesetzt (' . ($z['passwort_geaendert'] ?? '?') . ')' : 'FEHLT') . "\n";
        echo "Authenticator: " . (!empty($z['totp_secret']) ? 'gekoppelt' : 'nicht gekoppelt') . "\n";
        $akt = array_filter($s['ip'] ?? [], fn($e) => ($e['bis'] ?? 0) > time());
        echo "Gesperrte IPs: " . (count($akt) ? implode(', ', array_keys($akt)) : 'keine') . (($s['_gesamt']['bis'] ?? 0) > time() ? "  – GESAMTSPERRE aktiv" : '') . "\n";
        break;

    default:
        fwrite(STDERR, "Unbekannt: $cmd (einrichten|passwort|totp-neu|entsperren|status)\n");
        exit(1);
}
