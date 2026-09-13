-- Book Courts - Schema-Installation
--
-- Idempotent: das Skript kann mehrfach laufen, ohne Daten zu zerstoeren.
-- Es legt ausschliesslich Objekte im Ziel-Schema an und fasst nichts ausserhalb an.
--
-- Aufruf (Standard-Schema book_courts):
--   psql -d <datenbank> -f pg.sql
-- Aufruf mit abweichendem Schema:
--   psql -d <datenbank> -v schema_name=mein_schema -f pg.sql
--
-- ACHTUNG: Dieses Skript loescht NICHTS. Zum vollstaendigen Zuruecksetzen einer
-- Entwicklungs-Datenbank bewusst und von Hand:
--   DROP SCHEMA book_courts CASCADE;
-- Niemals gegen eine Datenbank, in der weitere Anwendungen liegen.

-- Default nur setzen, wenn nicht per -v uebergeben
\if :{?schema_name}
\else
\set schema_name book_courts
\endif

\echo 'Installiere Book-Courts-Schema in:' :schema_name

CREATE SCHEMA IF NOT EXISTS :"schema_name";
SET search_path TO :"schema_name";

-- Für gen_random_uuid()
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- 1) Basistypen
-- CREATE TYPE kennt kein IF NOT EXISTS, daher Pruefung im DO-Block.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_type t
                 JOIN pg_namespace n ON n.oid = t.typnamespace
                 WHERE t.typname = 'user_role' AND n.nspname = current_schema()) THEN
    CREATE TYPE user_role AS ENUM ('user', 'admin');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_type t
                 JOIN pg_namespace n ON n.oid = t.typnamespace
                 WHERE t.typname = 'booking_source' AND n.nspname = current_schema()) THEN
    CREATE TYPE booking_source AS ENUM ('single', 'series');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_type t
                 JOIN pg_namespace n ON n.oid = t.typnamespace
                 WHERE t.typname = 'booking_status' AND n.nspname = current_schema()) THEN
    CREATE TYPE booking_status AS ENUM ('active', 'canceled');
  END IF;
END
$$;

-- 2) Tabellen
CREATE TABLE IF NOT EXISTS app_user (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  email TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  full_name TEXT NOT NULL,
  role user_role NOT NULL DEFAULT 'user',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- Case-insensitive E-Mail-Eindeutigkeit (ohne citext)
CREATE UNIQUE INDEX IF NOT EXISTS app_user_email_ci_unique ON app_user (lower(email));

CREATE TABLE IF NOT EXISTS court (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name TEXT NOT NULL UNIQUE,
  location TEXT,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS booking (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  title TEXT NOT NULL DEFAULT '',
  user_id UUID NOT NULL REFERENCES app_user(id),
  court_id UUID NOT NULL REFERENCES court(id),
  time_span TSTZRANGE NOT NULL,
  status booking_status NOT NULL DEFAULT 'active',
  source booking_source NOT NULL,
  series_id UUID,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT booking_grid_chk CHECK (
    lower(time_span) = date_trunc('minute', lower(time_span)) AND
    upper(time_span) = date_trunc('minute', upper(time_span)) AND
    EXTRACT(MINUTE FROM lower(time_span))::int % 30 = 0 AND
    EXTRACT(MINUTE FROM upper(time_span))::int % 30 = 0 AND
    upper(time_span) > lower(time_span)
  )
);
CREATE INDEX IF NOT EXISTS booking_time_idx ON booking USING GIST (time_span);

CREATE TABLE IF NOT EXISTS recurring_series (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  title TEXT NOT NULL DEFAULT '',
  user_id UUID NOT NULL REFERENCES app_user(id),
  court_id UUID NOT NULL REFERENCES court(id),
  weekday SMALLINT NOT NULL CHECK (weekday BETWEEN 0 AND 6),
  start_time TIME NOT NULL,
  duration_min INTEGER NOT NULL CHECK (duration_min > 0 AND duration_min % 30 = 0),
  timezone TEXT NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS recurring_exception (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  series_id UUID NOT NULL REFERENCES recurring_series(id) ON DELETE CASCADE,
  occur_date DATE NOT NULL,
  canceled BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (series_id, occur_date)
);

-- 3) Trigger zur Kollisionsprüfung (ohne btree_gist)
-- Die Funktionskoerper sind bewusst NICHT schema-qualifiziert: psql ersetzt
-- Variablen innerhalb von $$-Bloecken nicht. Stattdessen verdrahtet jede Funktion
-- ihren search_path fest - damit ist sie schema-agnostisch und zugleich gegen
-- search_path-Manipulation abgesichert.
CREATE OR REPLACE FUNCTION prevent_overlap()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog, :"schema_name"
AS $$
BEGIN
  IF NEW.status = 'active' AND EXISTS (
    SELECT 1
    FROM booking b
    WHERE b.court_id = NEW.court_id
      AND b.status   = 'active'
      AND b.time_span && NEW.time_span
      AND b.id IS DISTINCT FROM NEW.id
  ) THEN
    RAISE EXCEPTION 'Booking overlaps with existing reservation for court %', NEW.court_id;
  END IF;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_booking_no_overlap ON booking;
CREATE TRIGGER trg_booking_no_overlap
BEFORE INSERT OR UPDATE ON booking
FOR EACH ROW EXECUTE FUNCTION prevent_overlap();

-- 4) create_booking-Funktion
CREATE OR REPLACE FUNCTION create_booking(
  p_user_id    UUID,
  p_court_id   UUID,
  p_start_at   TIMESTAMPTZ,
  p_end_at     TIMESTAMPTZ,
  p_source     booking_source DEFAULT 'single',
  p_series_id  UUID DEFAULT NULL,
  p_title      TEXT DEFAULT NULL
)
RETURNS booking
LANGUAGE plpgsql
SET search_path = pg_catalog, :"schema_name"
AS $$
DECLARE
  v_booking      booking;
  v_lock_key     BIGINT;
  v_user_active  BOOLEAN;
  v_court_active BOOLEAN;
  v_title        TEXT := COALESCE(p_title, '');
BEGIN
  -- Basics
  IF p_start_at IS NULL OR p_end_at IS NULL THEN
    RAISE EXCEPTION 'Start und Ende dürfen nicht NULL sein';
  END IF;
  IF p_end_at <= p_start_at THEN
    RAISE EXCEPTION 'Ende (%) muss nach Start (%) liegen', p_end_at, p_start_at;
  END IF;

  -- 30-Minuten-Raster
  IF date_trunc('minute', p_start_at) <> p_start_at
     OR date_trunc('minute', p_end_at) <> p_end_at
     OR (EXTRACT(MINUTE FROM p_start_at)::INT % 30) <> 0
     OR (EXTRACT(MINUTE FROM p_end_at)::INT % 30) <> 0
  THEN
    RAISE EXCEPTION 'Start/Ende müssen auf dem 30-Minuten-Raster liegen (hh:00/hh:30)';
  END IF;
  IF MOD(EXTRACT(EPOCH FROM (p_end_at - p_start_at))::INT, 1800) <> 0 THEN
    RAISE EXCEPTION 'Dauer muss ein Vielfaches von 30 Minuten sein';
  END IF;

  -- Nutzer/Platz aktiv?
  SELECT u.is_active INTO v_user_active
  FROM app_user u WHERE u.id = p_user_id;
  IF v_user_active IS DISTINCT FROM TRUE THEN
    RAISE EXCEPTION 'Nutzer % ist inaktiv oder existiert nicht', p_user_id;
  END IF;

  SELECT c.is_active INTO v_court_active
  FROM court c WHERE c.id = p_court_id;
  IF v_court_active IS DISTINCT FROM TRUE THEN
    RAISE EXCEPTION 'Platz % ist inaktiv oder existiert nicht', p_court_id;
  END IF;

  -- Serien-Validierung
  IF p_source = 'series' THEN
    IF p_series_id IS NULL THEN
      RAISE EXCEPTION 'series_id muss gesetzt sein, wenn source=series';
    END IF;
    PERFORM 1 FROM recurring_series s
     WHERE s.id = p_series_id AND s.is_active = TRUE;
    IF NOT FOUND THEN
      RAISE EXCEPTION 'Serien-ID % existiert nicht oder ist inaktiv', p_series_id;
    END IF;
  ELSE
    IF p_series_id IS NOT NULL THEN
      RAISE EXCEPTION 'series_id darf nur bei source=series gesetzt sein';
    END IF;
  END IF;

  -- Pro-Platz serialisieren
  v_lock_key := hashtextextended(p_court_id::text, 0);
  PERFORM pg_advisory_xact_lock(v_lock_key);

  -- Konfliktprüfung
  IF EXISTS (
    SELECT 1
    FROM booking b
    WHERE b.court_id = p_court_id
      AND b.status   = 'active'
      AND b.time_span && tstzrange(p_start_at, p_end_at, '[)')
  ) THEN
    RAISE EXCEPTION 'Konflikt: Zeitraum % – % auf Platz % belegt',
      p_start_at, p_end_at, p_court_id;
  END IF;

  -- Insert inkl. Titel
  INSERT INTO booking (user_id, court_id, time_span, status, source, series_id, title)
  VALUES (p_user_id, p_court_id, tstzrange(p_start_at, p_end_at, '[)'), 'active', p_source, p_series_id, v_title)
  RETURNING * INTO v_booking;

  RETURN v_booking;
END;
$$;

-- 5) Aufraeumen alter Daten
CREATE OR REPLACE FUNCTION prune_old(days_back integer DEFAULT 14)
RETURNS TABLE(deleted_bookings int, deleted_series int, deleted_exceptions int)
LANGUAGE plpgsql
SET search_path = pg_catalog, :"schema_name"
AS $$
DECLARE
  v_cutoff      timestamptz := (now() AT TIME ZONE 'Europe/Berlin') - make_interval(days => days_back);
  v_bookings    int := 0;
  v_series      int := 0;
  v_ex          int := 0;
  v_tmp         int := 0;
  v_series_ids  uuid[] := '{}';
BEGIN
  /* 1) Serien finden, deren letztes materialisiertes Ende < v_cutoff liegt
        oder (falls keine Buchungen) deren end_date < v_cutoff ist.        */
  SELECT array_agg(s.id)
    INTO v_series_ids
  FROM recurring_series s
  LEFT JOIN (
      SELECT series_id, max(upper(time_span)) AS last_end
      FROM booking
      WHERE series_id IS NOT NULL
      GROUP BY series_id
  ) lo ON lo.series_id = s.id
  WHERE
      (lo.last_end IS NOT NULL AND lo.last_end < v_cutoff)
   OR (lo.last_end IS NULL AND s.end_date IS NOT NULL AND (s.end_date::timestamptz) < v_cutoff);

  /* 2) Zuerst: Exceptions zu diesen Serien löschen */
  IF v_series_ids IS NOT NULL AND array_length(v_series_ids, 1) > 0 THEN
    DELETE FROM recurring_exception e
     WHERE e.series_id = ANY (v_series_ids);
    GET DIAGNOSTICS v_tmp = ROW_COUNT;
    v_ex := v_ex + v_tmp;

    /* 3) Buchungen dieser Serien löschen */
    DELETE FROM booking b
     WHERE b.series_id = ANY (v_series_ids);
    GET DIAGNOSTICS v_tmp = ROW_COUNT;
    v_bookings := v_bookings + v_tmp;

    /* 4) Serien selbst löschen */
    DELETE FROM recurring_series s
     WHERE s.id = ANY (v_series_ids);
    GET DIAGNOSTICS v_tmp = ROW_COUNT;
    v_series := v_series + v_tmp;
  END IF;

  /* 5) Übrige (nicht-Serien-)Buchungen löschen, die komplett vor Cutoff endeten */
  DELETE FROM booking b
   WHERE b.series_id IS NULL
     AND upper(b.time_span) < v_cutoff;
  GET DIAGNOSTICS v_tmp = ROW_COUNT;
  v_bookings := v_bookings + v_tmp;

  RETURN QUERY SELECT v_bookings, v_series, v_ex;
END;
$$;

-- 6) Freie Plätze in einem Zeitraum
CREATE OR REPLACE FUNCTION free_courts(
  p_start timestamptz,
  p_end   timestamptz
)
RETURNS TABLE (id uuid, name text)
LANGUAGE sql
STABLE
SET search_path = pg_catalog, :"schema_name"
AS $$
  SELECT c.id, c.name
  FROM court c
  WHERE c.is_active
    AND NOT EXISTS (
      SELECT 1
      FROM booking b
      WHERE b.court_id = c.id
        AND b.status = 'active'
        AND b.time_span && tstzrange(p_start, p_end, '[)')
    )
  ORDER BY c.name;
$$;

\echo 'Fertig.'
