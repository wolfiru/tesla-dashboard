<?php
// Einstellungen des Tesla-Dashboards.
// Die persönlichen Werte stehen in config.local.php – die legt ./install.sh an (nicht im Git, siehe .gitignore).
// Hier stehen nur Vorgaben; alles, was config.local.php definiert, hat Vorrang.
$td_lokal = getenv('TD_CONFIG') ?: __DIR__ . '/config.local.php';
if (is_readable($td_lokal)) require $td_lokal;
unset($td_lokal);
function td_vorgabe(string $name, $wert): void { if (!defined($name)) define($name, $wert); }

// ── TeslaMate-Datenbank (PostgreSQL) ─────────────────────────────────────
td_vorgabe('DB_HOST', '127.0.0.1');
td_vorgabe('DB_PORT', '5432');
td_vorgabe('DB_NAME', 'teslamate');
td_vorgabe('DB_USER', 'tesla_dashboard');   // eigene Rolle: lesen + auswertung.aktualisieren(), kein Zugriff auf Tokens
td_vorgabe('DB_PASS', '');
td_vorgabe('TESLAMATE_CAR_ID', 1);          // Auto in TeslaMate (cars.id)

// ── Fahrzeugwerte über MQTT (Verriegelung, Türen, Wächtermodus …), siehe lib/fahrzeugwerte.php ──
td_vorgabe('ZUSTAND_QUELLE', 'keine');      // 'mqtt' | 'iobroker' | 'keine'
td_vorgabe('MQTT_HOST', '127.0.0.1');
td_vorgabe('MQTT_PORT', 1883);
td_vorgabe('MQTT_USER', '');
td_vorgabe('MQTT_PASS', '');
td_vorgabe('MQTT_TLS', false);
td_vorgabe('MQTT_NAMESPACE', '');           // wie MQTT_NAMESPACE bei TeslaMate (meist leer)
td_vorgabe('IOBROKER_REST', 'http://127.0.0.1:8093');

// ── DaheimLader (Wallbox per Modbus, optional) ───────────────────────────
td_vorgabe('DAHEIM_API', '');               // z. B. 'http://192.168.1.20:7070' – leer = kein Wallbox-Tab

// ── Fahrzeug und Akku ────────────────────────────────────────────────────
td_vorgabe('FAHRZEUG_NAME', 'Tesla');
td_vorgabe('AKKU_NEU_KWH', 75.0);           // nutzbare Kapazität im Neuzustand (z. B. Model 3 LR ≈ 75, Model X 100D ≈ 98,5)
td_vorgabe('WERKS_REICHWEITE_KM', 500.0);   // Reichweite laut Werk bei 100 % (für die Degradationskurve)
td_vorgabe('TPMS_NOMINAL_BAR', 2.9);        // Reifendruck-Sollwert

// ── Kosten ───────────────────────────────────────────────────────────────
td_vorgabe('STROMPREIS_CT', 25.0);          // daheim: Durchschnitt je kWh brutto inkl. Netzgebühren, Abgaben, USt (ohne Grundgebühren)
td_vorgabe('STROMPREIS_FREMD_CT', 50.0);    // anderswo (Ladesäulen), Annahme
td_vorgabe('HEIM_LAT', 0.0);                // Ladungen im Umkreis gelten als „daheim“
td_vorgabe('HEIM_LON', 0.0);
td_vorgabe('HEIM_RADIUS_M', 250);

// ── Zugang ───────────────────────────────────────────────────────────────
td_vorgabe('TD_DATEN', '/var/lib/tesla-dashboard');            // Passwort-Hash, Sitzungen, Protokoll (gehört www-data)
td_vorgabe('TD_CF_KONFIG', '/etc/tesla-dashboard/cloudflare-access.php');
td_vorgabe('LOKALE_NETZE', ['192.168.0.0/16', '10.0.0.0/8', '172.16.0.0/12']);   // hier: Passwort, Sitzung 7 Tage
td_vorgabe('CLOUDFLARE_REICHT', false);     // true: über Cloudflare Access (Tunnel) kein eigenes Passwort mehr
td_vorgabe('NUR_HTTPS', true);            // Anmelde-Cookie nur über HTTPS (false nur für reines Heimnetz ohne Zertifikat)
td_vorgabe('SESSION_TIMEOUT', 28800);       // 8 h Inaktivität (von außen)
td_vorgabe('TIMEZONE', 'Europe/Vienna');

date_default_timezone_set(TIMEZONE);
