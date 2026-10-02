-- TeslaMate-Auswertungen für das Tesla-Dashboard (Tabs „Statistik“, „Pendeln“, Auffälligkeiten)
-- Schema "auswertung": wird von TeslaMate-Updates nicht angefasst.
-- Idempotent: kann jederzeit erneut eingespielt werden (z. B. nach einem Restore):
--   docker compose exec -T database psql -U teslamate -d teslamate < sql/auswertung.sql   (install.sh macht das)
--
-- Idee: Die Rohdaten (positions, ~4 Punkte/s beim Fahren) werden pro abgeschlossener Fahrt EINMAL
-- verdichtet (auswertung.aktualisieren()). Das Dashboard liest nur die kleinen Tabellen und Sichten.
-- TeslaMate speichert Zeitstempel in UTC ohne Zeitzone; für Tag/Monat wird auf Europe/Vienna umgerechnet.

CREATE SCHEMA IF NOT EXISTS auswertung;

-- ── Tabellen ──────────────────────────────────────────────────────────────

-- Eine Zeile pro abgeschlossener Fahrt
CREATE TABLE IF NOT EXISTS auswertung.fahrt (
  drive_id      integer PRIMARY KEY,
  start_date    timestamp NOT NULL,
  km            numeric NOT NULL,     -- drives.distance
  kwh           numeric NOT NULL,     -- Netto-Energie aus der Leistungskurve (inkl. Rekuperation)
  kwh_rekup     numeric NOT NULL,     -- zurückgewonnene Energie (positiv)
  wh_km         numeric,              -- kwh / km
  kwh_reichweite numeric,             -- Gegenprobe: Δ rated_range × cars.efficiency
  dauer_min     numeric,
  v_schnitt     numeric,              -- km/h über die Fahrzeit
  temp          numeric,              -- Ø Außentemperatur
  klima_anteil  numeric,              -- Anteil der Fahrzeit mit Klima/Heizung an (0..1)
  ascent        integer,
  descent       integer,
  berechnet_am  timestamptz NOT NULL DEFAULT now()
);

-- Verbrauch je Fahrt aufgeteilt nach Temperatur- und Geschwindigkeitsbereich
CREATE TABLE IF NOT EXISTS auswertung.fahrt_bins (
  drive_id   integer  NOT NULL,
  temp_bin   smallint NOT NULL,   -- Untergrenze in 5-°C-Schritten, z. B. -5 = -5 … <0 °C
  speed_bin  smallint NOT NULL,   -- 0, 30, 50, 70, 90, 110, 130 (Untergrenze km/h)
  klima      boolean  NOT NULL,
  km         numeric  NOT NULL,
  kwh        numeric  NOT NULL,
  sek        numeric  NOT NULL,
  PRIMARY KEY (drive_id, temp_bin, speed_bin, klima)
);

-- Reifendruck je Kalendertag (nur abgeschlossene Tage)
CREATE TABLE IF NOT EXISTS auswertung.tag_reifen (
  datum date PRIMARY KEY,
  fl numeric, fr numeric, rl numeric, rr numeric,
  temp numeric
);

-- ── Verdichtung ───────────────────────────────────────────────────────────

-- Verarbeitet bis zu max_fahrten noch nicht ausgewertete, abgeschlossene Fahrten
-- und die noch fehlenden Reifendruck-Tage. Gibt die Zahl der neuen Fahrten zurück.
CREATE OR REPLACE FUNCTION auswertung.aktualisieren(max_fahrten integer DEFAULT 50)
RETURNS integer
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = public, auswertung
AS $$
DECLARE
  ids integer[];
  eff numeric;
BEGIN
  SELECT array_agg(id) INTO ids FROM (
    SELECT d.id FROM drives d
    WHERE d.end_date IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM auswertung.fahrt f WHERE f.drive_id = d.id)
    ORDER BY d.id LIMIT max_fahrten
  ) x;

  IF ids IS NOT NULL THEN
    SELECT efficiency INTO eff FROM cars ORDER BY id LIMIT 1;

    CREATE TEMP TABLE seg ON COMMIT DROP AS
    WITH p AS (
      SELECT drive_id, date, speed, power, is_climate_on, outside_temp,
             count(outside_temp)  OVER (PARTITION BY drive_id ORDER BY date) AS tgrp,
             count(is_climate_on) OVER (PARTITION BY drive_id ORDER BY date) AS kgrp,
             -- erste bekannte Klima-Angabe der Fahrt (für die Punkte davor)
             first_value(is_climate_on) OVER (PARTITION BY drive_id ORDER BY (is_climate_on IS NULL), date
                                              ROWS BETWEEN UNBOUNDED PRECEDING AND UNBOUNDED FOLLOWING) AS klima_erst
      FROM positions WHERE drive_id = ANY(ids)
    ), p2 AS (
      SELECT *,
             first_value(outside_temp) OVER (PARTITION BY drive_id, tgrp ORDER BY date) AS temp_ff,
             first_value(is_climate_on) OVER (PARTITION BY drive_id, kgrp ORDER BY date) AS klima_ff,
             lead(date)     OVER (PARTITION BY drive_id ORDER BY date) AS nd
      FROM p
    )
    SELECT p2.drive_id,
           extract(epoch FROM p2.nd - p2.date)                           AS sek,
           coalesce(p2.speed, 0) * extract(epoch FROM p2.nd - p2.date) / 3600.0 AS km,  -- km-Stand zittert, v·t nicht
           coalesce(p2.power, 0) * extract(epoch FROM p2.nd - p2.date) / 3600.0 AS kwh,
           coalesce(p2.speed, 0)                                         AS speed,
           -- Klima-Status wird (wie die Temperatur) nur selten mitgeschrieben: letzten bekannten Wert fortschreiben
           coalesce(p2.klima_ff, p2.klima_erst, false)                   AS klima,
           coalesce(p2.temp_ff, d.outside_temp_avg)                      AS temp
    FROM p2 JOIN drives d ON d.id = p2.drive_id
    WHERE p2.nd IS NOT NULL
      AND p2.nd - p2.date BETWEEN interval '0' AND interval '30 seconds';  -- Lücken nicht hochrechnen

    INSERT INTO auswertung.fahrt_bins (drive_id, temp_bin, speed_bin, klima, km, kwh, sek)
    SELECT drive_id,
           (floor(coalesce(temp, 15) / 5) * 5)::smallint,
           (CASE WHEN speed < 30 THEN 0 WHEN speed < 50 THEN 30 WHEN speed < 70 THEN 50
                 WHEN speed < 90 THEN 70 WHEN speed < 110 THEN 90 WHEN speed < 130 THEN 110
                 ELSE 130 END)::smallint,
           klima, sum(km), sum(kwh), sum(sek)
    FROM seg GROUP BY 1, 2, 3, 4
    ON CONFLICT DO NOTHING;

    INSERT INTO auswertung.fahrt (drive_id, start_date, km, kwh, kwh_rekup, wh_km, kwh_reichweite,
                                  dauer_min, v_schnitt, temp, klima_anteil, ascent, descent)
    SELECT d.id, d.start_date, coalesce(d.distance, 0),
           coalesce(s.kwh, 0), coalesce(s.rekup, 0),
           CASE WHEN d.distance > 0.5 THEN s.kwh / d.distance::numeric * 1000 END,
           (d.start_rated_range_km - d.end_rated_range_km)::numeric * eff,
           d.duration_min,
           CASE WHEN d.duration_min > 0 THEN d.distance::numeric / d.duration_min * 60 END,
           coalesce(d.outside_temp_avg, s.temp),
           s.klima_anteil, d.ascent, d.descent
    FROM drives d
    LEFT JOIN (
      SELECT drive_id, sum(kwh) AS kwh, -sum(least(kwh, 0)) AS rekup, avg(temp) AS temp,
             sum(sek) FILTER (WHERE klima) / nullif(sum(sek), 0) AS klima_anteil
      FROM seg GROUP BY drive_id
    ) s ON s.drive_id = d.id
    WHERE d.id = ANY(ids)
    ON CONFLICT DO NOTHING;

    DROP TABLE seg;
  END IF;

  -- Reifendruck: nur Tage vor heute, die noch fehlen (ab dem letzten erfassten Tag)
  INSERT INTO auswertung.tag_reifen (datum, fl, fr, rl, rr, temp)
  SELECT (p.date AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Vienna')::date,
         round(avg(tpms_pressure_fl), 2), round(avg(tpms_pressure_fr), 2),
         round(avg(tpms_pressure_rl), 2), round(avg(tpms_pressure_rr), 2),
         round(avg(outside_temp), 1)
  FROM positions p
  WHERE p.tpms_pressure_fl IS NOT NULL
    AND p.date >= coalesce((SELECT max(datum) + 1 FROM auswertung.tag_reifen), '2000-01-01')::timestamp - interval '1 day'
    AND (p.date AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Vienna')::date
        < (now() AT TIME ZONE 'Europe/Vienna')::date
  GROUP BY 1
  ON CONFLICT (datum) DO NOTHING;

  RETURN coalesce(array_length(ids, 1), 0);
END $$;

-- Alles verwerfen und neu rechnen (z. B. nach Änderungen an der Logik)
CREATE OR REPLACE FUNCTION auswertung.neu_berechnen()
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, auswertung AS $$
DECLARE n integer := 0; k integer;
BEGIN
  TRUNCATE auswertung.fahrt, auswertung.fahrt_bins, auswertung.tag_reifen;
  LOOP
    k := auswertung.aktualisieren(200);
    n := n + k;
    EXIT WHEN k = 0;
  END LOOP;
  RETURN n;
END $$;

-- ── Sichten ───────────────────────────────────────────────────────────────
-- Vorher löschen: CREATE OR REPLACE VIEW kann Spaltentypen nicht ändern
DROP VIEW IF EXISTS auswertung.ladung, auswertung.standverlust, auswertung.monat;

-- Ladevorgänge angereichert: AC/DC, Leistung, Verluste, Kapazitätsschätzung, km-Stand
CREATE OR REPLACE VIEW auswertung.ladung AS
SELECT cp.id,
       cp.start_date, cp.end_date, cp.duration_min,
       cp.start_battery_level, cp.end_battery_level,
       cp.charge_energy_added, cp.charge_energy_used, cp.cost,
       cp.outside_temp_avg,
       coalesce(c.dc, false)                                               AS dc,
       c.p_avg, c.p_max, c.phasen, c.ampere,
       CASE WHEN cp.charge_energy_used > 0
            THEN cp.charge_energy_added / cp.charge_energy_used * 100 END   AS effizienz_pct,
       CASE WHEN cp.end_battery_level - cp.start_battery_level >= 10
            THEN cp.charge_energy_added / ((cp.end_battery_level - cp.start_battery_level) / 100.0) END
                                                                           AS kapazitaet_kwh,
       CASE WHEN cp.end_battery_level >= 50
            THEN cp.end_rated_range_km / cp.end_battery_level * 100 END     AS reichweite_100_km,
       pos.odometer                                                         AS km_stand,
       g.name                                                               AS ort
FROM charging_processes cp
LEFT JOIN LATERAL (
  SELECT bool_or(fast_charger_present) AS dc,
         avg(charger_power) FILTER (WHERE charger_power > 0) AS p_avg,
         max(charger_power) AS p_max,
         max(charger_phases) AS phasen,
         max(charger_actual_current) AS ampere
  FROM charges WHERE charging_process_id = cp.id
) c ON true
LEFT JOIN positions pos ON pos.id = cp.position_id
LEFT JOIN geofences g ON g.id = cp.geofence_id
WHERE cp.end_date IS NOT NULL;

-- Standverlust zwischen zwei Fahrten ohne Ladevorgang dazwischen (mind. 2 h geparkt)
CREATE OR REPLACE VIEW auswertung.standverlust AS
WITH d AS (
  SELECT id, end_date, end_rated_range_km,
         lead(start_date)          OVER (ORDER BY start_date) AS naechster_start,
         lead(start_rated_range_km) OVER (ORDER BY start_date) AS naechster_range
  FROM drives WHERE end_date IS NOT NULL
)
SELECT d.id AS nach_fahrt, d.end_date AS von, d.naechster_start AS bis,
       extract(epoch FROM d.naechster_start - d.end_date) / 3600 AS stunden,
       (d.end_rated_range_km - d.naechster_range)::numeric       AS verlust_km,
       (d.end_rated_range_km - d.naechster_range)::numeric * (SELECT efficiency::numeric FROM cars ORDER BY id LIMIT 1)
                                                                  AS verlust_kwh
FROM d
WHERE d.naechster_start IS NOT NULL
  AND d.naechster_start - d.end_date >= interval '2 hours'
  AND NOT EXISTS (SELECT 1 FROM charging_processes cp
                  WHERE cp.start_date < d.naechster_start AND coalesce(cp.end_date, now()) > d.end_date);

-- Monatsübersicht (Monat in österreichischer Zeit)
CREATE OR REPLACE VIEW auswertung.monat AS
WITH f AS (
  SELECT date_trunc('month', start_date AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Vienna')::date AS monat,
         count(*) AS fahrten, sum(km) AS km, sum(kwh) AS kwh, sum(kwh_rekup) AS kwh_rekup,
         sum(km * temp) / nullif(sum(km) FILTER (WHERE temp IS NOT NULL), 0) AS temp
  FROM auswertung.fahrt GROUP BY 1
), l AS (
  SELECT date_trunc('month', start_date AT TIME ZONE 'UTC' AT TIME ZONE 'Europe/Vienna')::date AS monat,
         count(*) AS ladungen, sum(charge_energy_added) AS geladen_kwh,
         sum(charge_energy_used) AS bezogen_kwh, sum(cost) AS kosten
  FROM charging_processes WHERE end_date IS NOT NULL GROUP BY 1
)
SELECT coalesce(f.monat, l.monat) AS monat,
       coalesce(f.fahrten, 0) AS fahrten, coalesce(f.km, 0) AS km, coalesce(f.kwh, 0) AS kwh,
       coalesce(f.kwh_rekup, 0) AS kwh_rekup,
       CASE WHEN f.km > 1 THEN f.kwh / f.km * 1000 END AS wh_km,
       f.temp,
       coalesce(l.ladungen, 0) AS ladungen, coalesce(l.geladen_kwh, 0) AS geladen_kwh,
       coalesce(l.bezogen_kwh, 0) AS bezogen_kwh, l.kosten
FROM f FULL JOIN l ON l.monat = f.monat;

-- ── Rechte ────────────────────────────────────────────────────────────────
-- Dashboard-Rolle (tesla_dashboard) darf lesen und die Verdichtung anstoßen; DaheimLader (daheim_ro) darf lesen
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'daheim_ro') THEN
    GRANT USAGE ON SCHEMA auswertung TO daheim_ro;
    GRANT SELECT ON ALL TABLES IN SCHEMA auswertung TO daheim_ro;
    ALTER DEFAULT PRIVILEGES IN SCHEMA auswertung GRANT SELECT ON TABLES TO daheim_ro;
  END IF;
END $$;
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tesla_dashboard') THEN
    GRANT USAGE ON SCHEMA auswertung TO tesla_dashboard;
    GRANT SELECT ON ALL TABLES IN SCHEMA auswertung TO tesla_dashboard;
    ALTER DEFAULT PRIVILEGES IN SCHEMA auswertung GRANT SELECT ON TABLES TO tesla_dashboard;
    GRANT EXECUTE ON FUNCTION auswertung.aktualisieren(integer) TO tesla_dashboard;
  END IF;
END $$;
REVOKE ALL ON FUNCTION auswertung.aktualisieren(integer) FROM PUBLIC;
REVOKE ALL ON FUNCTION auswertung.neu_berechnen() FROM PUBLIC;

-- Erstbefüllung bzw. nach Logik-Änderung alles neu rechnen
SELECT auswertung.neu_berechnen() AS fahrten;

-- ── Pendeln: Fahrten zwischen festen Orten (26.09.2026) ──────────────────
-- Ein Ort = Mittelpunkt + Radius. Eine Fahrt zählt, wenn sie im Radius des einen Ortes beginnt und im Radius
-- eines anderen endet. Fahrten mit Zwischenstopp zählen nicht (TeslaMate teilt sie in zwei Fahrten).
-- Orte ändern/ergänzen: UPDATE/INSERT in auswertung.orte (bleibt beim erneuten Einspielen erhalten).
-- install.sh fragt sie ab (z. B. aus den Geofences von TeslaMate); ohne Orte bleibt der Tab „Pendeln“ leer.
CREATE TABLE IF NOT EXISTS auswertung.orte (
  name     text PRIMARY KEY,
  lat      double precision NOT NULL,
  lon      double precision NOT NULL,
  radius_m integer NOT NULL
);

DROP VIEW IF EXISTS auswertung.pendel;
-- Erst die abgeschlossenen Fahrten mit Start-/Endpunkt holen (MATERIALIZED: ~1 Zeile je Fahrt, Positionen per Primärschlüssel),
-- dann gegen die wenigen Orte prüfen. Ohne das rechnet PostgreSQL bei Zeitfiltern die Distanz über alle Positionen (Sekunden).
CREATE VIEW auswertung.pendel AS
WITH d AS MATERIALIZED (
  SELECT d.id, d.start_date, d.end_date, d.distance, d.duration_min, d.speed_max, d.ascent, d.descent,
         sp.latitude::float8 AS s_lat, sp.longitude::float8 AS s_lon, sp.battery_level AS soc_start,
         ep.latitude::float8 AS e_lat, ep.longitude::float8 AS e_lon, ep.battery_level AS soc_ende
  FROM drives d
  JOIN positions sp ON sp.id = d.start_position_id
  JOIN positions ep ON ep.id = d.end_position_id
  WHERE d.end_date IS NOT NULL
)
SELECT d.id                                   AS drive_id,
       a.name                                 AS von,
       b.name                                 AS nach,
       a.name || ' → ' || b.name              AS richtung,
       d.start_date, d.end_date,
       d.distance::numeric                    AS km,
       d.duration_min,
       f.wh_km, f.kwh, f.v_schnitt, f.temp, f.klima_anteil,
       d.speed_max,
       d.soc_start,
       d.soc_ende,
       d.ascent, d.descent
FROM d
JOIN auswertung.orte a
  ON public.earth_distance(public.ll_to_earth(d.s_lat, d.s_lon), public.ll_to_earth(a.lat, a.lon)) <= a.radius_m
JOIN auswertung.orte b
  ON b.name <> a.name
 AND public.earth_distance(public.ll_to_earth(d.e_lat, d.e_lon), public.ll_to_earth(b.lat, b.lon)) <= b.radius_m
LEFT JOIN auswertung.fahrt f ON f.drive_id = d.id;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'daheim_ro') THEN
    GRANT SELECT ON auswertung.orte, auswertung.pendel TO daheim_ro;
  END IF;
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'tesla_dashboard') THEN
    GRANT SELECT ON auswertung.orte, auswertung.pendel TO tesla_dashboard;
  END IF;
END $$;

-- Statistik für den Planer: ohne sie schätzt PostgreSQL die kleinen Tabellen viel zu groß und startet die
-- (auf dem Raspberry Pi sekundenlange) JIT-Kompilierung. Siehe auch ALTER ROLE … SET jit = off im Installer.
ANALYZE auswertung.fahrt;
ANALYZE auswertung.fahrt_bins;
ANALYZE auswertung.orte;
ANALYZE auswertung.tag_reifen;
