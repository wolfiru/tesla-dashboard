#!/usr/bin/env bash
# Tesla-Dashboard – Installation und Einrichtung
#   sudo ./install.sh            erstmalig einrichten oder Einstellungen ändern (fragt alles ab)
#   sudo ./install.sh --update   nur Programmdateien aktualisieren, Einstellungen bleiben
# Voraussetzung: TeslaMate läuft (Docker oder eigene PostgreSQL), Debian/Raspberry Pi OS/Ubuntu.
set -euo pipefail

QUELLE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ZIEL_VORGABE=/var/www/tesla-dashboard
KONF_DIR=/etc/tesla-dashboard
DATEN_DIR=/var/lib/tesla-dashboard

# ── Hilfen ──────────────────────────────────────────────────────────────
fett=$'\e[1m'; gruen=$'\e[32m'; gelb=$'\e[33m'; rot=$'\e[31m'; aus=$'\e[0m'
titel()  { echo; echo "${fett}── $* ──${aus}"; }
ok()     { echo "  ${gruen}✓${aus} $*"; }
warn()   { echo "  ${gelb}!${aus} $*"; }
fehler() { echo "  ${rot}✗${aus} $*" >&2; }
frage()  { local f="$1" v="${2:-}" a; read -r -p "  $f${v:+ [$v]}: " a || true; echo "${a:-$v}"; }
geheim() { local a; read -r -s -p "  $1: " a || true; echo >&2; echo "$a"; }
janein() { local a; read -r -p "  $1 [${2:-j}/$( [ "${2:-j}" = j ] && echo n || echo j )]: " a || true; a="${a:-${2:-j}}"; [[ "$a" =~ ^[jJyY] ]]; }
php_str() { php -r 'echo var_export($argv[1], true);' -- "$1"; }

[ "$(id -u)" -eq 0 ] || { fehler "Bitte mit sudo starten: sudo ./install.sh"; exit 1; }

# ── Pakete ──────────────────────────────────────────────────────────────
titel "Pakete prüfen"
fehlt=()
command -v php >/dev/null || fehlt+=(php)
MODULE=" $(php -m 2>/dev/null | tr 'A-Z\n' 'a-z ' || true) "   # einmal einlesen (grep -q in einer Pipe + pipefail ist unzuverlässig)
for m in pgsql curl mbstring; do [[ "$MODULE" == *" $m "* ]] || fehlt+=(php-$m); done
command -v apache2 >/dev/null || command -v nginx >/dev/null || fehlt+=(apache2 libapache2-mod-php)
command -v psql >/dev/null || command -v docker >/dev/null || fehlt+=(postgresql-client)
if [ ${#fehlt[@]} -gt 0 ]; then
  warn "Fehlt: ${fehlt[*]}"
  if janein "Jetzt mit apt installieren?"; then apt-get update -qq && apt-get install -y "${fehlt[@]}"; else exit 1; fi
fi
ok "PHP $(php -r 'echo PHP_VERSION;'), pgsql, curl und mbstring vorhanden"

# ── Dateien kopieren ────────────────────────────────────────────────────
ZIEL=$(frage "Installationsordner (Web-Wurzel des Dashboards)" "$ZIEL_VORGABE")
mkdir -p "$ZIEL"
if [ "$QUELLE" != "$ZIEL" ]; then
  if command -v rsync >/dev/null; then
    rsync -a --delete --exclude='.git' --exclude='config.local.php' --exclude='install.sh' --exclude='docs' "$QUELLE"/ "$ZIEL"/
  else
    (cd "$QUELLE" && tar --exclude=.git --exclude=config.local.php --exclude=docs -cf - .) | (cd "$ZIEL" && tar -xf -)
  fi
fi
chown -R root:www-data "$ZIEL"; find "$ZIEL" -type d -exec chmod 755 {} +; find "$ZIEL" -type f -exec chmod 644 {} +
ok "Programmdateien in $ZIEL"
if [ "${1:-}" = "--update" ]; then
  [ -f "$ZIEL/config.local.php" ] || { fehler "Noch nicht eingerichtet – ohne --update starten."; exit 1; }
  ok "Aktualisiert. Einstellungen unverändert."; exit 0
fi
[ -f "$ZIEL/config.local.php" ] && warn "Es gibt schon eine config.local.php – sie wird am Ende ersetzt (Sicherung: config.local.php.alt)."

# ── TeslaMate-Datenbank ─────────────────────────────────────────────────
titel "TeslaMate-Datenbank"
PSQL=()   # Befehl mit Admin-Rechten (für Schema und Rolle)
DB_CONT=""
if command -v docker >/dev/null; then
  DB_CONT=$(docker ps --format '{{.Names}} {{.Image}}' | awk '$2 ~ /postgres/ {print $1}' | head -1)
fi
if [ -n "$DB_CONT" ] && janein "PostgreSQL-Container „$DB_CONT“ gefunden – ist das die TeslaMate-Datenbank?"; then
  PG_USER=$(docker exec "$DB_CONT" printenv POSTGRES_USER 2>/dev/null || echo teslamate)
  PG_DB=$(docker exec "$DB_CONT" printenv POSTGRES_DB 2>/dev/null || echo teslamate)
  PSQL=(docker exec -i "$DB_CONT" psql -v ON_ERROR_STOP=1 -q -U "$PG_USER" -d "$PG_DB")
  DB_NAME="$PG_DB"
  PORT_MAP=$(docker port "$DB_CONT" 5432/tcp 2>/dev/null | head -1 || true)
  if [ -n "$PORT_MAP" ]; then
    DB_HOST=${PORT_MAP%:*}; DB_PORT=${PORT_MAP##*:}; [ "$DB_HOST" = "0.0.0.0" ] && DB_HOST=127.0.0.1
    ok "Datenbank ist erreichbar unter $DB_HOST:$DB_PORT"
  else
    warn "Der Container gibt Port 5432 nicht nach außen frei. Das Dashboard braucht ihn."
    echo "    In der docker-compose.yml von TeslaMate beim Dienst „database“ ergänzen und neu starten:"
    echo "        ports:"
    echo "          - \"127.0.0.1:5432:5432\""
    echo "    (docker compose up -d) – danach hier weiter."
    read -r -p "  Enter, wenn erledigt … " _ || true
    DB_HOST=127.0.0.1; DB_PORT=5432
  fi
else
  DB_HOST=$(frage "Datenbank-Server" "127.0.0.1"); DB_PORT=$(frage "Port" "5432"); DB_NAME=$(frage "Datenbank" "teslamate")
  PG_USER=$(frage "Admin-Benutzer (für Schema und Rolle, wird nicht gespeichert)" "teslamate")
  PG_PASS=$(geheim "Passwort dieses Benutzers")
  PSQL=(env PGPASSWORD="$PG_PASS" psql -v ON_ERROR_STOP=1 -q -h "$DB_HOST" -p "$DB_PORT" -U "$PG_USER" -d "$DB_NAME")
fi
"${PSQL[@]}" -tAc "SELECT count(*) FROM cars" >/dev/null || { fehler "Keine Verbindung zur TeslaMate-Datenbank."; exit 1; }
ok "TeslaMate-Datenbank gefunden ($("${PSQL[@]}" -tAc "SELECT count(*) FROM drives") Fahrten)"

q() { "${PSQL[@]}" -tA -F $'\t' -c "$1"; }

# Auto auswählen
mapfile -t AUTOS < <(q "SELECT id, coalesce(name,'?'), coalesce(model,'?'), coalesce(trim_badging,'') FROM cars ORDER BY id")
CAR_ID=1
if [ ${#AUTOS[@]} -gt 1 ]; then
  for z in "${AUTOS[@]}"; do IFS=$'\t' read -r i n m t <<<"$z"; echo "    $i) $n – Model $m $t"; done
  CAR_ID=$(frage "Welches Auto (Nummer)" "1")
fi
IFS=$'\t' read -r _ AUTO_NAME AUTO_MODEL AUTO_TRIM <<<"$(q "SELECT id, coalesce(name,'Tesla'), coalesce(model,''), coalesce(trim_badging,'') FROM cars WHERE id = $CAR_ID")"
ok "Auto: $AUTO_NAME (Model $AUTO_MODEL $AUTO_TRIM)"

# Rolle für das Dashboard (nur lesen)
DB_USER=tesla_dashboard
DB_PASS=$(php -r 'echo bin2hex(random_bytes(18));')
if [ "$(q "SELECT 1 FROM pg_roles WHERE rolname = '$DB_USER'")" = "1" ]; then
  "${PSQL[@]}" -c "ALTER ROLE $DB_USER LOGIN PASSWORD '$DB_PASS'"
  ok "Rolle $DB_USER: neues Passwort gesetzt"
else
  "${PSQL[@]}" -c "CREATE ROLE $DB_USER LOGIN PASSWORD '$DB_PASS'"
  ok "Rolle $DB_USER angelegt"
fi
"${PSQL[@]}" <<SQL
GRANT CONNECT ON DATABASE "$DB_NAME" TO $DB_USER;
GRANT USAGE ON SCHEMA public TO $DB_USER;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO $DB_USER;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO $DB_USER;
-- JIT lohnt sich bei den kleinen Abfragen nie und kostet auf einem Raspberry Pi Sekunden je Abfrage
ALTER ROLE $DB_USER SET jit = off;
SQL
ok "Leserechte vergeben (TeslaMates Zugangs-Tokens im Schema „private“ bleiben gesperrt)"

TZ_NAME=$(frage "Zeitzone" "$(cat /etc/timezone 2>/dev/null || echo Europe/Vienna)")
"${PSQL[@]}" -c "CREATE EXTENSION IF NOT EXISTS cube; CREATE EXTENSION IF NOT EXISTS earthdistance;" >/dev/null 2>&1 || true
sed "s#Europe/Vienna#$TZ_NAME#g" "$QUELLE/sql/auswertung.sql" | "${PSQL[@]}" >/dev/null
ok "Auswertungs-Schema eingespielt (Fahrten verdichtet)"

# ── Orte: daheim und Pendeln ────────────────────────────────────────────
titel "Orte"
mapfile -t ZAEUNE < <(q "SELECT name, latitude, longitude, radius FROM geofences ORDER BY name")
if [ ${#ZAEUNE[@]} -gt 0 ]; then
  echo "    Geofences aus TeslaMate:"; n=0
  for z in "${ZAEUNE[@]}"; do n=$((n+1)); IFS=$'\t' read -r nm la lo ra <<<"$z"; printf "    %2d) %s (%.4f, %.4f, %s m)\n" $n "$nm" "$la" "$lo" "$ra"; done
fi
waehle_ort() {   # $1 = Frage → setzt ORT_NAME ORT_LAT ORT_LON ORT_RADIUS
  local a; a=$(frage "$1 – Nummer eines Geofence oder „lat,lon“ (leer = überspringen)" "")
  ORT_NAME=""; [ -z "$a" ] && return 1
  if [[ "$a" =~ ^[0-9]+$ ]] && [ "$a" -ge 1 ] && [ "$a" -le ${#ZAEUNE[@]} ]; then
    IFS=$'\t' read -r ORT_NAME ORT_LAT ORT_LON ORT_RADIUS <<<"${ZAEUNE[$((a-1))]}"
  else
    ORT_LAT=${a%%,*}; ORT_LON=${a##*,}; ORT_RADIUS=250; ORT_NAME=$(frage "Name des Orts" "Ort")
  fi
}
HEIM_LAT=0; HEIM_LON=0
if waehle_ort "Daheim (Wallbox): Ladungen in diesem Umkreis kosten den Heimstrompreis"; then
  HEIM_LAT=$ORT_LAT; HEIM_LON=$ORT_LON; ok "Daheim: $ORT_NAME"
fi
echo "    Pendeln: Fahrten zwischen zwei (oder mehr) Orten werden getrennt ausgewertet, z. B. Wohnort ↔ Arbeit."
while waehle_ort "Pendel-Ort hinzufügen"; do
  R=$(frage "Radius in m (ganze Stadt: 5000)" "5000")
  "${PSQL[@]}" -c "INSERT INTO auswertung.orte (name, lat, lon, radius_m) VALUES ('${ORT_NAME//\'/\'\'}', $ORT_LAT, $ORT_LON, $R) ON CONFLICT (name) DO UPDATE SET lat = EXCLUDED.lat, lon = EXCLUDED.lon, radius_m = EXCLUDED.radius_m"
  ok "Pendel-Ort $ORT_NAME"
done
"${PSQL[@]}" -c "ANALYZE auswertung.orte" >/dev/null

# ── Fahrzeugwerte über MQTT ─────────────────────────────────────────────
titel "Fahrzeugzustand (Verriegelung, Türen, Wächtermodus, Ladeziel)"
echo "    Diese Werte veröffentlicht TeslaMate per MQTT. Quelle:"
echo "      1) MQTT-Broker von TeslaMate (z. B. Mosquitto) – üblich"
echo "      2) ioBroker REST-Adapter (wenn TeslaMate in ioBroker veröffentlicht)"
echo "      3) keine – Karte „Fahrzeugzustand“ entfällt"
MQTT_HOST=127.0.0.1; MQTT_PORT=1883; MQTT_USER=""; MQTT_PASS=""; MQTT_NS=""; IOB=http://127.0.0.1:8093
case "$(frage "Auswahl" "1")" in
  1) ZQ=mqtt
     MQTT_HOST=$(frage "MQTT-Server" "127.0.0.1"); MQTT_PORT=$(frage "Port" "1883")
     MQTT_USER=$(frage "Benutzer (leer = ohne)" ""); [ -n "$MQTT_USER" ] && MQTT_PASS=$(geheim "Passwort")
     MQTT_NS=$(frage "MQTT_NAMESPACE von TeslaMate (meist leer)" "");;
  2) ZQ=iobroker; IOB=$(frage "ioBroker REST-Adresse" "$IOB");;
  *) ZQ=keine;;
esac

# ── DaheimLader ─────────────────────────────────────────────────────────
titel "Wallbox"
DAHEIM=""
if janein "Gibt es einen DaheimLader (Wallbox-Steuerung per Modbus, github.com/wolfiru/daheimlader)?" n; then
  DAHEIM=$(frage "Adresse des DaheimLaders" "http://127.0.0.1:7070")
  if curl -fsS -m 5 "$DAHEIM/api/status" >/dev/null; then ok "DaheimLader antwortet"; else warn "DaheimLader antwortet (noch) nicht – der Wallbox-Tab zeigt dann eine Fehlermeldung."; fi
else
  ok "Ohne Wallbox-Tab"
fi

# ── Fahrzeug und Kosten ─────────────────────────────────────────────────
titel "Fahrzeug und Kosten"
case "$AUTO_MODEL" in
  X) KAP=98.5; WERK=560;; S) KAP=95; WERK=600;; Y) KAP=75; WERK=530;; 3) KAP=75; WERK=600;; *) KAP=75; WERK=500;;
esac
FZ_NAME=$(frage "Anzeigename des Autos" "$AUTO_NAME")
KAP=$(frage "Nutzbare Akkukapazität im Neuzustand in kWh" "$KAP")
WERK=$(frage "Reichweite laut Werk bei 100 % in km" "$WERK")
TPMS=$(frage "Reifendruck-Sollwert in bar" "2.9")
echo "    Strompreis daheim: Durchschnitt je kWh BRUTTO, inklusive Netzgebühren, Abgaben und USt"
echo "    (Grundgebühren/Pauschalen nicht mitrechnen – die fallen ohnehin an)."
PREIS=$(frage "Preis in ct/kWh – oder „r“ zum Ausrechnen aus den Teilen" "25")
if [ "$PREIS" = r ]; then
  E=$(frage "Energiepreis netto ct/kWh" "12"); N=$(frage "Netzentgelte netto ct/kWh (Netznutzung + Netzverlust)" "9")
  A=$(frage "Abgaben netto ct/kWh (Elektrizitätsabgabe, Förderbeiträge …)" "2"); U=$(frage "USt in %" "20")
  PREIS=$(php -r 'printf("%.2f", ($argv[1] + $argv[2] + $argv[3]) * (1 + $argv[4] / 100));' -- "$E" "$N" "$A" "$U")
  ok "ergibt $PREIS ct/kWh brutto"
fi
FREMD=$(frage "Strompreis anderswo (Ladesäulen), Annahme, ct/kWh" "50")

# ── Zugang ──────────────────────────────────────────────────────────────
titel "Zugang"
NETZ=$(ip -o -4 route show scope link 2>/dev/null | awk '{print $1}' | grep -v '^169\.254' | head -1)
NETZ=$(frage "Heimnetz (dort: Passwort, angemeldet bleiben 7 Tage)" "${NETZ:-192.168.0.0/16}")
HTTPS=true
janein "Läuft das Dashboard über HTTPS (Zertifikat oder Reverse-Proxy)?" j || { HTTPS=false; warn "Ohne HTTPS: nur im Heimnetz verwenden!"; }
CF=false
if janein "Von außen über einen Cloudflare-Tunnel mit Cloudflare Access?" n; then
  CF_TEAM=$(frage "Access-Team-Domain (z. B. meinteam.cloudflareaccess.com)" "")
  CF_AUD=$(frage "Application Audience (AUD) Tag" "")
  CF_MAIL=$(frage "zugelassene E-Mail-Adresse (leer = alle, die Access zulässt)" "")
  mkdir -p "$KONF_DIR"
  { echo "<?php return ['team' => $(php_str "$CF_TEAM"), 'aud' => $(php_str "$CF_AUD"), 'emails' => [$( [ -n "$CF_MAIL" ] && php_str "$CF_MAIL")]];"; } > "$KONF_DIR/cloudflare-access.php"
  chown root:www-data "$KONF_DIR/cloudflare-access.php"; chmod 640 "$KONF_DIR/cloudflare-access.php"
  janein "Reicht über den Tunnel die Cloudflare-Anmeldung (kein zusätzliches Dashboard-Passwort)?" j && CF=true
  ok "Cloudflare Access eingerichtet"
fi

# ── config.local.php schreiben ──────────────────────────────────────────
titel "Einstellungen speichern"
[ -f "$ZIEL/config.local.php" ] && cp -p "$ZIEL/config.local.php" "$ZIEL/config.local.php.alt"
cat > "$ZIEL/config.local.php" <<PHP
<?php
// Erzeugt von install.sh am $(date '+%d.%m.%Y %H:%M') – Vorgaben und Erklärungen siehe config.php
define('DB_HOST', $(php_str "$DB_HOST"));
define('DB_PORT', $(php_str "$DB_PORT"));
define('DB_NAME', $(php_str "$DB_NAME"));
define('DB_USER', $(php_str "$DB_USER"));
define('DB_PASS', $(php_str "$DB_PASS"));
define('TESLAMATE_CAR_ID', $CAR_ID);
define('ZUSTAND_QUELLE', $(php_str "$ZQ"));
define('MQTT_HOST', $(php_str "$MQTT_HOST"));
define('MQTT_PORT', $MQTT_PORT);
define('MQTT_USER', $(php_str "$MQTT_USER"));
define('MQTT_PASS', $(php_str "$MQTT_PASS"));
define('MQTT_NAMESPACE', $(php_str "$MQTT_NS"));
define('IOBROKER_REST', $(php_str "$IOB"));
define('DAHEIM_API', $(php_str "$DAHEIM"));
define('FAHRZEUG_NAME', $(php_str "$FZ_NAME"));
define('AKKU_NEU_KWH', $KAP);
define('WERKS_REICHWEITE_KM', $WERK);
define('TPMS_NOMINAL_BAR', $TPMS);
define('STROMPREIS_CT', $PREIS);
define('STROMPREIS_FREMD_CT', $FREMD);
define('HEIM_LAT', $HEIM_LAT);
define('HEIM_LON', $HEIM_LON);
define('LOKALE_NETZE', [$(php_str "$NETZ")]);
define('NUR_HTTPS', $HTTPS);
define('CLOUDFLARE_REICHT', $CF);
define('TIMEZONE', $(php_str "$TZ_NAME"));
PHP
chown root:www-data "$ZIEL/config.local.php"; chmod 640 "$ZIEL/config.local.php"
ok "$ZIEL/config.local.php (nur root und Webserver lesbar)"

# Verbindungstest so, wie das Dashboard ihn sieht
php -r 'require $argv[1]; $c = @pg_connect(sprintf("host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5", DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS)); if (!$c) { fwrite(STDERR, "keine Verbindung\n"); exit(1); } echo pg_fetch_result(pg_query($c, "SELECT count(*) FROM positions"), 0), " Positionen lesbar\n";' "$ZIEL/config.php" \
  && ok "Dashboard erreicht die Datenbank" || fehler "Dashboard erreicht die Datenbank NICHT (Host/Port/pg_hba.conf prüfen)"
if [ "$ZQ" != keine ]; then
  php -r 'require $argv[1]; require $argv[2]; $r = td_fahrzeugwerte(); echo $r ? count($r["werte"]) . " Fahrzeugwerte\n" : ""; exit($r ? 0 : 1);' "$ZIEL/config.php" "$ZIEL/lib/fahrzeugwerte.php" \
    && ok "MQTT-Werte kommen an" || warn "Keine MQTT-Werte – Adresse/Zugang prüfen; das Dashboard läuft trotzdem."
fi

# ── Zugang anlegen ──────────────────────────────────────────────────────
titel "Login-Passwort"
php "$ZIEL/tools/zugang.php" einrichten >/dev/null
for versuch in 1 2 3; do
  PW1=$(geheim "Login-Passwort für das Dashboard (mind. 12 Zeichen)"); PW2=$(geheim "Wiederholen")
  if [ "$PW1" != "$PW2" ]; then warn "Stimmt nicht überein."; PW1=""; continue; fi
  if [ ${#PW1} -lt 12 ]; then warn "Zu kurz."; PW1=""; continue; fi
  break
done
[ -n "$PW1" ] || { fehler "Kein Passwort gesetzt – später: sudo php $ZIEL/tools/zugang.php passwort"; }
[ -n "$PW1" ] && printf '%s\n' "$PW1" | php "$ZIEL/tools/zugang.php" passwort --stdin; unset PW1 PW2

# ── Webserver ───────────────────────────────────────────────────────────
titel "Webserver"
if command -v apache2 >/dev/null && janein "Apache-Site anlegen?" j; then
  NAME=$(frage "Hostname (z. B. tesla.example.com oder tesla.local)" "$(hostname -f 2>/dev/null || hostname)")
  cat > /etc/apache2/sites-available/tesla-dashboard.conf <<APACHE
<VirtualHost *:80>
    ServerName $NAME
    DocumentRoot $ZIEL
    <Directory $ZIEL>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/tesla-dashboard-error.log
    CustomLog \${APACHE_LOG_DIR}/tesla-dashboard-access.log combined
</VirtualHost>
APACHE
  a2enmod -q rewrite headers >/dev/null; a2ensite -q tesla-dashboard >/dev/null
  systemctl reload apache2 2>/dev/null || apache2ctl graceful 2>/dev/null || service apache2 reload || true
  ok "Apache: http://$NAME/"
  [ "$HTTPS" = true ] && echo "    HTTPS: z. B. mit „sudo certbot --apache -d $NAME“ oder über den Reverse-Proxy/Tunnel."
else
  echo "    Web-Wurzel auf $ZIEL zeigen lassen (PHP, .htaccess bzw. Zugriff auf lib/ und tools/ sperren)."
fi

titel "Fertig"
echo "  Dashboard: $ZIEL   Einstellungen: $ZIEL/config.local.php   Daten: $DATEN_DIR"
echo "  Ändern: sudo ./install.sh   Aktualisieren: git pull && sudo ./install.sh --update"
