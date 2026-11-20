# Weather Collector

A small PHP 8.3 backend that, once a day, fetches **that day's forecast** for one or more locations
from **Open-Meteo** and **WeatherAPI**, normalises it, and stores **temperature and precipitation at
hourly (24 values) and daily (1 value) resolution** in **PostgreSQL**. A read-only JSON API serves the collected data.

There are no runtime dependencies beyond PHP extensions (PDO/pgsql, cURL). The code is plain PHP
with a hand-wired container, so the whole thing is small enough to read in one sitting.

## Quick start

```bash
cp .env.example .env          # add your free WEATHERAPI_KEY (optional, Open-Meteo works without one)
docker compose up -d --build
```

This starts four services:

| service     | what it does                                                                 |
|-------------|------------------------------------------------------------------------------|
| `db`        | PostgreSQL 16, data kept in the `db-data` volume                             |
| `migrate`   | one-shot: applies migrations and syncs `config/locations.json`, then exits   |
| `scheduler` | long-running daily collector (`bin/console schedule`)                        |
| `api`       | JSON API on <http://localhost:8080>                                          |

Each location is collected once a day at `FETCH_TIME` (06:00 by default) in **the location's own
timezone**, and only that local day is requested. If the scheduler starts after `FETCH_TIME`, it collects right away, so data
shows up within a minute. To fetch by hand:

```bash
docker compose run --rm api php bin/console fetch          # skips pairs already collected today
docker compose run --rm api php bin/console fetch --force  # re-fetch and overwrite today's data
docker compose run --rm api php bin/console status
curl "http://localhost:8080/api/locations/berlin/forecasts/daily"
```

### Locations

Edit `config/locations.json`. It is mounted into the containers, so you don't need to rebuild. Then
run `docker compose run --rm api php bin/console locations:sync` (or restart the stack). Locations
removed from the file are deactivated, and their history is kept.

```json
[{ "slug": "berlin", "name": "Berlin, Germany", "latitude": 52.52, "longitude": 13.405, "timezone": "Europe/Berlin" }]
```

## Design

```
config/locations.json ─┐
                       ▼
 scheduler ──► SchedulePolicy ──► Collector ──► WeatherProvider (OpenMeteo | WeatherApi)
  (tick/60s)   (due? retry?)        │                 │  RetryingHttpClient ─► CurlHttpClient
                                    │                 ▼
                                    │           ForecastResult (normalised: UTC hours, local days, °C, mm)
                                    ▼
                      PostgreSQL: fetch_runs, hourly_forecasts, daily_forecasts
                                    ▲
                     api (Apache + public/index.php ─► Api)
```

* **`src/Provider/*`**: one adapter per API. Each adapter turns the provider's request format,
  units, timestamps and error codes into a common `ForecastResult`. To add a provider, write one
  class that implements `WeatherProvider`, add a row to `providers`, and register it in `App`.
* **`src/Service/Collector`**: fetches today's forecast from one provider for one location, keeps
  only the rows for that local day, stores them in a single transaction, and records the attempt in `fetch_runs`. One failing provider or location
  never affects the others.
* **`src/Service/SchedulePolicy` + `Scheduler`**: decide what is due and run it.
* **`src/Api/Api`**: read API with input validation and optional token authentication.

### Data model

| table              | grain                                                    | notes |
|--------------------|----------------------------------------------------------|-------|
| `locations`        | one row per configured location                          | synced from the config file |
| `providers`        | one row per provider                                     | |
| `fetch_runs`       | one row per **attempt** (provider × location × day)      | status, HTTP status, retryable flag, error, counts, duration, raw response (JSONB) |
| `hourly_forecasts` | provider × location × `valid_at` (UTC hour)              | `temperature_c`, `precipitation_mm`; 24 rows per day (23/25 on DST days) |
| `daily_forecasts`  | provider × location × `forecast_date` (local day)        | min / max / mean temperature, `precipitation_mm` |

Key decisions:

* **Only the current day is collected**: both providers are asked for exactly one day
  (`forecast_days=1` / `days=1`). As a safety net, anything outside that local day is discarded
  before storing. Data builds up one day at a time, so over time you get a daily series per provider.
* **Idempotent by design**: there are unique keys on (provider, location, hour) and (provider,
  location, day), and a partial unique index allows at most one successful run per
  provider/location/day. A forced re-fetch overwrites that day's rows (upsert) in one transaction
  and marks the old run `superseded`.
* **Hourly times are stored as `TIMESTAMPTZ` in UTC**, so they are never ambiguous around DST
  changes. **Daily rows use the location's local calendar day**, which is what "daily" means to a
  person.
* `NUMERIC` columns with CHECK constraints (e.g. precipitation ≥ 0).
* The raw provider response is stored per successful run (`STORE_RAW_PAYLOAD`), so data can be
  re-processed or audited later. Each payload is a few KB to about 100 KB per day.

### Handling differences between providers

| aspect | Open-Meteo | WeatherAPI | handling |
|---|---|---|---|
| auth | none | API key | provider is skipped (with a warning) if `WEATHERAPI_KEY` is empty |
| request | `forecast_days=1`, `timezone=<location tz>` | `days=1`, `q=lat,lon` | both return the location's current local day |
| response shape | parallel arrays per variable | nested `forecastday[].hour[]` objects | one adapter per provider → common domain objects |
| timestamps | requested as Unix time, aggregated in the requested `timezone` | `time_epoch` per hour, daily `date` in WeatherAPI's local tz | everything converted to UTC instants / local dates; a warning is raised if WeatherAPI's `tz_id` differs from the configured timezone |
| daily mean temperature | not requested | `avgtemp_c` | for Open-Meteo it is computed from the hourly series, **only for complete local days** (23/24/25 hours around DST) |
| hourly precipitation | sum over the *preceding* hour | precipitation for the hour as labelled | stored as each provider reports it. The difference is documented in `/api/providers` rather than silently shifted |
| units | requested explicitly (`celsius`, `mm`) and checked against `*_units` | `_c` / `_mm` fields | always °C and mm |
| errors | `{"error":true,"reason":…}` | `{"error":{"code":…,"message":…}}` | mapped to `ProviderException(retryable: bool)` |

Bad values are checked one at a time. A non-numeric or implausible value (temperature outside
−90…60 °C, precipitation < 0 or > 1000 mm) is stored as `NULL` and logged as a data-quality
warning, which is also kept on the run. It does not throw away the whole forecast. Structural
problems fail the run: missing sections, series of different lengths, unparseable timestamps, or a
response that doesn't contain today.

### When things go wrong

There are three layers:

1. **HTTP** (`RetryingHttpClient`): network errors, 408, 429 and 5xx are retried up to
   `HTTP_MAX_RETRIES` times with exponential backoff and jitter. `Retry-After` is honoured, capped
   at 30 s. Other 4xx responses are **not** retried.
2. **Provider classification**: every failure becomes a `ProviderException` with a `retryable` flag.
   Bad API key (1002/2006), quota exceeded (2007), disabled key (2008) and invalid location (1006)
   are permanent. Outages, rate limits and malformed payloads are retryable.
3. **Scheduler policy**: after a retryable failure, the pair is tried again every
   `RETRY_INTERVAL_MINUTES`, up to `MAX_ATTEMPTS_PER_DAY` attempts. After a **permanent** failure it
   is not retried until the next day, so a wrong key can't burn through the quota. If the service
   was down at `FETCH_TIME`, it catches up as soon as it comes back.

The rest of the failure handling:

* Every attempt, successful or not, is a row in `fetch_runs` with its error message and HTTP status.
  You can inspect it with `bin/console status`, `GET /api/runs?status=failed` or `GET /health`.
  `/health` reports `degraded` when a pair has had no successful day since yesterday.
* Any other unexpected error (a database error or a bug) is logged and recorded as a failed run, and
  the scheduler moves on. If the database is briefly unreachable, that tick is logged and the next
  tick tries again.
* Runs left `running` by a crash are closed as failed when the scheduler starts.
* `SIGTERM` is handled, so `docker compose stop` lets the current write finish.
* API keys are never logged, and they are redacted from error messages.
* Logs are JSON lines on stderr (`docker compose logs -f scheduler`).

### Free-tier limits

Each run makes one request per provider per location, and that one request returns both the hourly
and the daily series. With N locations that is N calls a day per provider:

* Open-Meteo: free for non-commercial use with a fair-use limit of about 10,000 calls/day.
* WeatherAPI free plan: a monthly call quota. Today's forecast fits in the free plan.

Skipping pairs that are already collected, bounded retries, and no retries on quota or auth errors
keep usage far below both limits. Also note that WeatherAPI's free plan doesn't officially include
HTTPS. The base URL is configurable (`WEATHERAPI_BASE_URL`) in case your plan needs plain HTTP.

## API

All responses are JSON. Temperatures are in °C, precipitation in mm, and times in UTC ISO 8601. If
`API_TOKEN` is set, every `/api/*` request needs `Authorization: Bearer <token>` (or `?token=`).

| endpoint | description |
|---|---|
| `GET /health` | DB status and latest run per provider/location (`ok` / `degraded`) |
| `GET /api/providers` | providers, whether they are configured, and notes on semantic differences |
| `GET /api/locations` | configured locations |
| `GET /api/locations/{slug}/forecasts/daily` | one row per provider per day. `from`, `to` (YYYY-MM-DD; default: last 30 days up to today), `provider=a,b` |
| `GET /api/locations/{slug}/forecasts/hourly` | hourly rows. `from`, `to` (YYYY-MM-DD as local midnight, or ISO date-time; default: today; max 31 d), `provider=a,b` |
| `GET /api/runs` | fetch log. `provider`, `location`, `status`, `limit` (≤ 500) |

Example:

```bash
curl "http://localhost:8080/api/locations/berlin/forecasts/daily?from=2026-11-19&to=2026-11-19"
```

```json
{
  "location": { "slug": "berlin", "name": "Berlin, Germany", "latitude": 52.52, "longitude": 13.405, "timezone": "Europe/Berlin" },
  "from": "2026-11-19",
  "to": "2026-11-19",
  "units": { "temperature": "°C", "precipitation": "mm", "time": "UTC (ISO 8601)" },
  "count": 2,
  "data": [
    { "provider": "open_meteo", "forecast_date": "2026-11-19",
      "temperature_min_c": 0.1, "temperature_max_c": 6.0, "temperature_mean_c": 3.0, "precipitation_mm": 0.6 },
    { "provider": "weatherapi", "forecast_date": "2026-11-19",
      "temperature_min_c": 1.0, "temperature_max_c": 7.0, "temperature_mean_c": 4.0, "precipitation_mm": 0.3 }
  ]
}
```

(The values are from the test fixtures.)

For ad-hoc analysis you can also query PostgreSQL directly. To do that, uncomment the `ports` line
of `db` in `docker-compose.yml`.

## CLI

```
bin/console migrate                 apply migrations + sync locations
bin/console locations:sync          sync locations from LOCATIONS_FILE
bin/console fetch [--force] [--provider=open_meteo] [--location=berlin]
bin/console schedule                run the daily scheduler (foreground)
bin/console status                  latest run per provider/location
```

`fetch` exits with code 1 if any pair failed, so it can also be driven by an external cron or CI
job instead of the built-in scheduler.

## Configuration

All configuration is done through environment variables (see `.env.example`):

| variable | default | |
|---|---|---|
| `WEATHERAPI_KEY` | – | WeatherAPI key. Without it, only Open-Meteo is collected |
| `FETCH_TIME` | `06:00` | daily collection time, in each location's own timezone |
| `RETRY_INTERVAL_MINUTES` / `MAX_ATTEMPTS_PER_DAY` | `30` / `4` | retry policy for transient failures |
| `PROVIDERS` | `open_meteo,weatherapi` | enabled providers |
| `HTTP_TIMEOUT_SECONDS` / `HTTP_MAX_RETRIES` | `15` / `2` | HTTP behaviour |
| `STORE_RAW_PAYLOAD` | `true` | keep raw responses |
| `API_TOKEN` | – | enables bearer-token auth for `/api/*` |
| `API_PORT` | `8080` | host port of the API |
| `DB_*` | `weather` | database credentials |

## Tests

There is a dependency-free runner. It runs unit tests for both adapters (including a DST
transition, bad values, error classification and key redaction) and for the retry logic. It also
runs integration tests against a real PostgreSQL database for storage, idempotency, retry policy,
scheduler and API.

```bash
# unit tests only (integration tests are skipped)
docker compose run --rm --no-deps api php tests/run.php

# including integration tests – WARNING: wipes the public schema of TEST_DB_NAME
docker compose exec db createdb -U weather weather_test
docker compose run --rm -e TEST_DB_HOST=db -e TEST_DB_NAME=weather_test -e TEST_DB_USER=weather -e TEST_DB_PASSWORD="$DB_PASSWORD" api php tests/run.php
```

## Possible next steps

* Store observed weather (e.g. Open-Meteo's archive API) to score each provider's same-day forecast.
* Retention or partitioning of `hourly_forecasts` if many locations are added (48 rows per location per day).
* Alerting on `/health` status `degraded`.
