-- Locations we collect forecasts for. Synced from config/locations.json.
CREATE TABLE locations (
    id          SERIAL PRIMARY KEY,
    slug        VARCHAR(64)   NOT NULL UNIQUE,
    name        VARCHAR(255)  NOT NULL,
    latitude    NUMERIC(8, 5) NOT NULL CHECK (latitude BETWEEN -90 AND 90),
    longitude   NUMERIC(8, 5) NOT NULL CHECK (longitude BETWEEN -180 AND 180),
    timezone    VARCHAR(64)   NOT NULL,
    active      BOOLEAN       NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

-- Weather data providers. Adding a provider = one row + one adapter class.
CREATE TABLE providers (
    code  VARCHAR(32)  PRIMARY KEY,
    name  VARCHAR(255) NOT NULL,
    url   VARCHAR(255) NOT NULL
);

INSERT INTO providers (code, name, url) VALUES
    ('open_meteo', 'Open-Meteo', 'https://open-meteo.com/'),
    ('weatherapi', 'WeatherAPI', 'https://www.weatherapi.com/');

-- One row per attempt to fetch one provider for one location.
-- forecast_date is the local day (at the location) that was collected.
CREATE TABLE fetch_runs (
    id             BIGSERIAL    PRIMARY KEY,
    provider       VARCHAR(32)  NOT NULL REFERENCES providers (code),
    location_id    INTEGER      NOT NULL REFERENCES locations (id) ON DELETE CASCADE,
    forecast_date  DATE         NOT NULL,
    status         VARCHAR(16)  NOT NULL CHECK (status IN ('running', 'success', 'failed', 'superseded')),
    started_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    finished_at    TIMESTAMPTZ,
    duration_ms    INTEGER,
    http_status    SMALLINT,
    retryable      BOOLEAN,
    error_message  TEXT,
    hourly_count   INTEGER,
    daily_count    INTEGER,
    raw_payload    JSONB
);

CREATE INDEX fetch_runs_lookup_idx ON fetch_runs (provider, location_id, forecast_date, status);
CREATE INDEX fetch_runs_started_idx ON fetch_runs (started_at DESC);

-- At most one successful fetch per provider/location/day.
CREATE UNIQUE INDEX fetch_runs_one_success_per_day
    ON fetch_runs (provider, location_id, forecast_date) WHERE status = 'success';

-- Hourly forecast values for the day of the fetch. One row per provider/location/hour;
-- fetch_run_id points at the run that wrote it.
CREATE TABLE hourly_forecasts (
    id                BIGSERIAL     PRIMARY KEY,
    fetch_run_id      BIGINT        NOT NULL REFERENCES fetch_runs (id) ON DELETE CASCADE,
    provider          VARCHAR(32)   NOT NULL REFERENCES providers (code),
    location_id       INTEGER       NOT NULL REFERENCES locations (id) ON DELETE CASCADE,
    valid_at          TIMESTAMPTZ   NOT NULL,  -- start of the hour, stored in UTC
    temperature_c     NUMERIC(5, 2),
    precipitation_mm  NUMERIC(7, 2) CHECK (precipitation_mm >= 0),
    UNIQUE (provider, location_id, valid_at)
);

CREATE INDEX hourly_forecasts_query_idx ON hourly_forecasts (location_id, valid_at, provider);

-- Daily forecast values; forecast_date is the local calendar day at the location.
CREATE TABLE daily_forecasts (
    id                  BIGSERIAL     PRIMARY KEY,
    fetch_run_id        BIGINT        NOT NULL REFERENCES fetch_runs (id) ON DELETE CASCADE,
    provider            VARCHAR(32)   NOT NULL REFERENCES providers (code),
    location_id         INTEGER       NOT NULL REFERENCES locations (id) ON DELETE CASCADE,
    forecast_date       DATE          NOT NULL,
    temperature_min_c   NUMERIC(5, 2),
    temperature_max_c   NUMERIC(5, 2),
    temperature_mean_c  NUMERIC(5, 2),
    precipitation_mm    NUMERIC(7, 2) CHECK (precipitation_mm >= 0),
    UNIQUE (provider, location_id, forecast_date)
);

CREATE INDEX daily_forecasts_query_idx ON daily_forecasts (location_id, forecast_date, provider);
