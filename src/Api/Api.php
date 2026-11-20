<?php

declare(strict_types=1);

namespace WeatherCollector\Api;

use WeatherCollector\App;
use WeatherCollector\Domain\Location;
use WeatherCollector\Provider\OpenMeteoProvider;
use WeatherCollector\Provider\WeatherApiProvider;

/**
 * Read-only JSON API over the collected data.
 */
final class Api
{
    private const UNITS = ['temperature' => '°C', 'precipitation' => 'mm', 'time' => 'UTC (ISO 8601)'];
    private const MAX_HOURLY_RANGE_DAYS = 31;
    private const MAX_DAILY_RANGE_DAYS = 366;

    private const PROVIDER_INFO = [
        OpenMeteoProvider::CODE => [
            'name' => 'Open-Meteo',
            'url' => 'https://open-meteo.com/',
            'notes' => [
                'hourly_precipitation' => 'Sum over the hour preceding valid_at.',
                'daily_temperature_mean' => 'Computed by this service from the hourly series (complete local days only).',
            ],
        ],
        WeatherApiProvider::CODE => [
            'name' => 'WeatherAPI',
            'url' => 'https://www.weatherapi.com/',
            'notes' => [
                'hourly_precipitation' => 'Precipitation for the hour labelled valid_at, as reported by WeatherAPI.',
                'daily_temperature_mean' => 'avgtemp_c as reported by WeatherAPI.',
            ],
        ],
    ];

    public function __construct(private readonly App $app)
    {
    }

    /** @param array<string, string> $query */
    public function handle(string $method, string $path, array $query, ?string $authorization): JsonResponse
    {
        try {
            if ($method !== 'GET' && $method !== 'HEAD') {
                throw new ApiException(405, 'Only GET requests are supported');
            }
            $path = '/' . trim($path, '/');

            if ($path === '/health') {
                return $this->health();
            }
            $this->authorize($query, $authorization);

            return match (true) {
                $path === '/' || $path === '/api' => $this->index(),
                $path === '/api/providers' => new JsonResponse(['data' => $this->providers()]),
                $path === '/api/locations' => new JsonResponse(['data' => $this->app->locations()->all()]),
                $path === '/api/runs' => $this->runs($query),
                (bool) preg_match('#^/api/locations/([^/]+)/forecasts/hourly$#', $path, $m) => $this->hourly($m[1], $query),
                (bool) preg_match('#^/api/locations/([^/]+)/forecasts/daily$#', $path, $m) => $this->daily($m[1], $query),
                default => throw ApiException::notFound(sprintf('No route for %s', $path)),
            };
        } catch (ApiException $e) {
            return new JsonResponse(['error' => ['status' => $e->status, 'message' => $e->getMessage()]], $e->status);
        } catch (\Throwable $e) {
            $this->app->logger()->error('API error', ['path' => $path, 'error' => $e->getMessage(), 'exception' => get_class($e)]);

            return new JsonResponse(['error' => ['status' => 500, 'message' => 'Internal server error']], 500);
        }
    }

    private function index(): JsonResponse
    {
        return new JsonResponse([
            'name' => 'weather-collector',
            'endpoints' => [
                'GET /health',
                'GET /api/providers',
                'GET /api/locations',
                'GET /api/locations/{slug}/forecasts/daily?from=YYYY-MM-DD&to=YYYY-MM-DD&provider=a,b',
                'GET /api/locations/{slug}/forecasts/hourly?from=...&to=...&provider=a,b',
                'GET /api/runs?provider=&location=&status=&limit=50',
            ],
        ]);
    }

    private function health(): JsonResponse
    {
        try {
            $this->app->pdo()->query('SELECT 1');
            $latest = $this->app->runs()->latestPerPair();
        } catch (\Throwable $e) {
            return new JsonResponse(['status' => 'error', 'database' => 'unreachable'], 503);
        }

        // Degraded when a pair has had no successful day since yesterday.
        $cutoff = (new \DateTimeImmutable('now'))->modify('-2 days')->format('Y-m-d');
        $stale = array_filter($latest, static fn (array $r) => $r['last_success_date'] === null || $r['last_success_date'] < $cutoff);

        return new JsonResponse([
            'status' => $stale === [] ? 'ok' : 'degraded',
            'database' => 'ok',
            'latest_runs' => $latest,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function providers(): array
    {
        $out = [];
        foreach ($this->app->providers() as $code => $provider) {
            $out[] = ['code' => $code, 'configured' => $provider->isConfigured()] + (self::PROVIDER_INFO[$code] ?? []);
        }

        return $out;
    }

    /** @param array<string, string> $query */
    private function daily(string $slug, array $query): JsonResponse
    {
        $location = $this->location($slug);
        $today = (new \DateTimeImmutable('now', $location->dateTimeZone()))->format('Y-m-d');
        $to = $this->date($query, 'to') ?? $today;
        $from = $this->date($query, 'from') ?? (new \DateTimeImmutable($to))->modify('-29 days')->format('Y-m-d');
        $this->assertRange(new \DateTimeImmutable($from), new \DateTimeImmutable($to), self::MAX_DAILY_RANGE_DAYS);

        $rows = $this->app->forecasts()->daily((int) $location->id, $this->providerList($query), $from, $to);

        return new JsonResponse([
            'location' => $this->locationInfo($location),
            'from' => $from,
            'to' => $to,
            'units' => self::UNITS,
            'count' => count($rows),
            'data' => $rows,
        ]);
    }

    /** @param array<string, string> $query */
    private function hourly(string $slug, array $query): JsonResponse
    {
        $location = $this->location($slug);
        $tz = $location->dateTimeZone();
        $from = $this->dateTime($query, 'from', $tz) ?? new \DateTimeImmutable('today', $tz);
        $to = $this->dateTime($query, 'to', $tz) ?? $from->modify('+1 day');
        $this->assertRange($from, $to, self::MAX_HOURLY_RANGE_DAYS);

        $utc = new \DateTimeZone('UTC');
        $rows = $this->app->forecasts()->hourly(
            (int) $location->id,
            $this->providerList($query),
            $from->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            $to->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        );

        return new JsonResponse([
            'location' => $this->locationInfo($location),
            'from' => $from->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            'to' => $to->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            'units' => self::UNITS,
            'count' => count($rows),
            'data' => $rows,
        ]);
    }

    /** @param array<string, string> $query */
    private function runs(array $query): JsonResponse
    {
        $filters = [];
        if (isset($query['provider'])) {
            $filters['provider'] = $this->providerList($query)[0];
        }
        if (isset($query['location'])) {
            $filters['location'] = $query['location'];
        }
        if (isset($query['status'])) {
            if (!in_array($query['status'], ['running', 'success', 'failed', 'superseded'], true)) {
                throw ApiException::badRequest('status must be one of running, success, failed, superseded');
            }
            $filters['status'] = $query['status'];
        }
        $limit = isset($query['limit']) ? filter_var($query['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]) : 50;
        if ($limit === false) {
            throw ApiException::badRequest('limit must be an integer between 1 and 500');
        }

        return new JsonResponse(['data' => $this->app->runs()->recent($filters, $limit)]);
    }

    private function location(string $slug): Location
    {
        return $this->app->locations()->findBySlug($slug) ?? throw ApiException::notFound(sprintf('Unknown location "%s"', $slug));
    }

    /** @return array<string, mixed> */
    private function locationInfo(Location $l): array
    {
        return ['slug' => $l->slug, 'name' => $l->name, 'latitude' => $l->latitude, 'longitude' => $l->longitude, 'timezone' => $l->timezone];
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<string>
     */
    private function providerList(array $query): array
    {
        if (!isset($query['provider']) || $query['provider'] === '') {
            return [];
        }
        $known = array_keys(self::PROVIDER_INFO);
        $list = array_values(array_unique(array_map('trim', explode(',', $query['provider']))));
        foreach ($list as $code) {
            if (!in_array($code, $known, true)) {
                throw ApiException::badRequest(sprintf('Unknown provider "%s" (known: %s)', $code, implode(', ', $known)));
            }
        }

        return $list;
    }

    /** @param array<string, string> $query */
    private function date(array $query, string $key): ?string
    {
        if (!isset($query[$key]) || $query[$key] === '') {
            return null;
        }
        $this->assertDate($query[$key], $key);

        return $query[$key];
    }

    private function assertDate(string $value, string $key): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw ApiException::badRequest(sprintf('%s must be a date in YYYY-MM-DD format', $key));
        }
    }

    /**
     * Accepts YYYY-MM-DD (local midnight at the location) or an ISO 8601 date-time.
     *
     * @param array<string, string> $query
     */
    private function dateTime(array $query, string $key, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $value = $query[$key] ?? '';
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $this->assertDate($value, $key);

            return new \DateTimeImmutable($value . ' 00:00:00', $tz);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?$/', $value)) {
            try {
                return new \DateTimeImmutable($value, $tz);
            } catch (\Exception) {
                // fall through
            }
        }
        throw ApiException::badRequest(sprintf('%s must be YYYY-MM-DD or an ISO 8601 date-time', $key));
    }

    private function assertRange(\DateTimeImmutable $from, \DateTimeImmutable $to, int $maxDays): void
    {
        if ($to < $from) {
            throw ApiException::badRequest('"to" must not be before "from"');
        }
        if ($to->getTimestamp() - $from->getTimestamp() > $maxDays * 86400) {
            throw ApiException::badRequest(sprintf('Range must not exceed %d days', $maxDays));
        }
    }

    /** @param array<string, string> $query */
    private function authorize(array $query, ?string $authorization): void
    {
        $token = $this->app->config->string('API_TOKEN');
        if ($token === '') {
            return;
        }
        $given = null;
        if ($authorization !== null && preg_match('/^Bearer\s+(.+)$/i', $authorization, $m)) {
            $given = trim($m[1]);
        }
        $given ??= $query['token'] ?? null;
        if (!is_string($given) || !hash_equals($token, $given)) {
            throw new ApiException(401, 'Missing or invalid API token');
        }
    }
}
