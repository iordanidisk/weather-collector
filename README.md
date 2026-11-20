# Weather Collector

A small PHP 8.3 backend that, once a day, fetches **that day's forecast** for one or more locations
from **Open-Meteo** and **WeatherAPI**, normalises it, and stores **temperature and precipitation at
hourly (24 values) and daily (1 value) resolution** in **PostgreSQL**. A read-only JSON API serves the collected data.

There are no runtime dependencies beyond PHP extensions (PDO/pgsql, cURL). The code is plain PHP
with a hand-wired container, so the whole thing is small enough to read in one sitting.

## Installation and Setup

```bash
git clone https://github.com/iordanidisk/weather-collector.git
cd weather-collector

cp .env.example .env
# Edit .env: set WEATHERAPI_KEY and choose a DB_PASSWORD

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

## Implementation choices

No framework was used since the application was simple and straightforward. If I had used a framework the project would be unnecessarily heavy.

## Possible next steps

* Locations management through the API or dashboard, with geocoding (e.g. Open-Meteo's geocoding API), instead of editing a JSON file.
* More providers through the existing adapter interface, plus an optional consensus value across providers.
