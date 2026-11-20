<?php

declare(strict_types=1);

namespace WeatherCollector\Provider;

use WeatherCollector\Domain\DailyForecast;
use WeatherCollector\Domain\ForecastResult;
use WeatherCollector\Domain\HourlyForecast;
use WeatherCollector\Domain\Location;
use WeatherCollector\Http\HttpClient;

/**
 * Open-Meteo forecast API (no key; free for non-commercial use, ~10k calls/day).
 *
 * Only the current local day is requested (forecast_days=1); hourly and daily
 * series come from that single request. Timestamps are requested
 * as Unix time so DST transitions are unambiguous; daily rows are aggregated by
 * Open-Meteo over the location's local day (timezone parameter).
 */
final class OpenMeteoProvider extends AbstractProvider
{
    public const CODE = 'open_meteo';

    public function __construct(
        HttpClient $http,
        private readonly string $baseUrl = 'https://api.open-meteo.com/v1',
    ) {
        parent::__construct($http);
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function fetch(Location $location): ForecastResult
    {
        [$response, $data] = $this->request(rtrim($this->baseUrl, '/') . '/forecast', [
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'hourly' => 'temperature_2m,precipitation',
            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum',
            'timezone' => $location->timezone,
            'timeformat' => 'unixtime',
            'forecast_days' => 1, // today only, in the location's timezone
            'temperature_unit' => 'celsius',
            'precipitation_unit' => 'mm',
        ]);

        if (!$response->isSuccessful()) {
            // Open-Meteo errors look like {"error": true, "reason": "..."}
            $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';
            throw $this->genericHttpError($response, $reason);
        }
        if ($data === null) {
            throw $this->invalidResponse('body is not valid JSON', $response->status);
        }

        return $this->parse($data, $location, $response->body);
    }

    /** @param array<mixed> $data */
    public function parse(array $data, Location $location, string $rawPayload = ''): ForecastResult
    {
        $this->warnings = [];
        if (($data['error'] ?? false) === true) {
            throw new ProviderException('Open-Meteo error: ' . ($data['reason'] ?? 'unknown'), false);
        }
        $tz = $location->dateTimeZone();

        $this->assertUnits($data['hourly_units'] ?? [], ['temperature_2m' => '°C', 'precipitation' => 'mm']);
        $this->assertUnits($data['daily_units'] ?? [], ['temperature_2m_max' => '°C', 'precipitation_sum' => 'mm']);

        $hourlySeries = $this->series($data, 'hourly', ['time', 'temperature_2m', 'precipitation']);
        $hourly = [];
        foreach ($hourlySeries['time'] as $i => $time) {
            $validAt = $this->toUtc($time, $tz);
            $where = 'hourly ' . $validAt->format('Y-m-d\TH:i\Z');
            $hourly[] = new HourlyForecast(
                $validAt,
                $this->temperature($hourlySeries['temperature_2m'][$i], $where),
                $this->precipitation($hourlySeries['precipitation'][$i], $where),
            );
        }

        $means = self::dailyMeansFromHourly($hourly, $tz);

        $dailySeries = $this->series($data, 'daily', ['time', 'temperature_2m_max', 'temperature_2m_min', 'precipitation_sum']);
        $daily = [];
        foreach ($dailySeries['time'] as $i => $time) {
            $date = $this->toUtc($time, $tz)->setTimezone($tz)->format('Y-m-d');
            $where = 'daily ' . $date;
            $daily[] = new DailyForecast(
                $date,
                $this->temperature($dailySeries['temperature_2m_min'][$i], $where),
                $this->temperature($dailySeries['temperature_2m_max'][$i], $where),
                $means[$date] ?? null,
                $this->precipitation($dailySeries['precipitation_sum'][$i], $where),
            );
        }

        if ($hourly === [] || $daily === []) {
            throw $this->invalidResponse('empty forecast series');
        }

        return new ForecastResult(self::CODE, $hourly, $daily, $rawPayload, $this->takeWarnings());
    }

    /**
     * @param array<mixed>  $data
     * @param list<string>  $keys
     *
     * @return array<string, list<mixed>>
     */
    private function series(array $data, string $section, array $keys): array
    {
        $block = $data[$section] ?? null;
        if (!is_array($block)) {
            throw $this->invalidResponse(sprintf('missing "%s" section', $section));
        }

        $series = [];
        foreach ($keys as $key) {
            if (!isset($block[$key]) || !is_array($block[$key]) || !array_is_list($block[$key])) {
                throw $this->invalidResponse(sprintf('missing or malformed "%s.%s"', $section, $key));
            }
            $series[$key] = $block[$key];
        }

        $lengths = array_unique(array_map('count', $series));
        if (count($lengths) !== 1) {
            throw $this->invalidResponse(sprintf('"%s" arrays have different lengths', $section));
        }

        return $series;
    }

    /** Accepts Unix timestamps (requested) or local ISO strings (defensive fallback). */
    private function toUtc(mixed $time, \DateTimeZone $tz): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        if (is_int($time) || (is_string($time) && ctype_digit($time))) {
            return (new \DateTimeImmutable('@' . $time))->setTimezone($utc);
        }
        if (is_string($time)) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $time, $tz)
                ?: \DateTimeImmutable::createFromFormat('!Y-m-d', $time, $tz);
            if ($parsed !== false) {
                return $parsed->setTimezone($utc);
            }
        }

        throw $this->invalidResponse('unparseable time value ' . json_encode($time));
    }

    /**
     * @param mixed                 $units
     * @param array<string, string> $expected
     */
    private function assertUnits(mixed $units, array $expected): void
    {
        if (!is_array($units)) {
            return;
        }
        foreach ($expected as $key => $unit) {
            if (isset($units[$key]) && $units[$key] !== $unit) {
                throw new ProviderException(sprintf('Open-Meteo returned %s in "%s", expected "%s"', $key, $units[$key], $unit), false);
            }
        }
    }
}
