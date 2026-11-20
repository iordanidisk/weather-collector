<?php

declare(strict_types=1);

namespace WeatherCollector\Provider;

use WeatherCollector\Domain\DailyForecast;
use WeatherCollector\Domain\ForecastResult;
use WeatherCollector\Domain\HourlyForecast;
use WeatherCollector\Domain\Location;
use WeatherCollector\Http\HttpClient;

/**
 * WeatherAPI.com forecast endpoint (API key required). Only the current local
 * day is requested (days=1). The free plan has a monthly call quota, so a single
 * call per location per day is made and quota/auth errors are never retried.
 */
final class WeatherApiProvider extends AbstractProvider
{
    public const CODE = 'weatherapi';

    /**
     * WeatherAPI error codes (https://www.weatherapi.com/docs/#intro-error-codes)
     * that are permanent for the rest of the day. Anything else (e.g. 9999
     * "internal application error") may be retried later.
     */
    private const PERMANENT_ERROR_CODES = [
        1002 => 'API key not provided',
        1003 => 'parameter q not provided',
        1005 => 'invalid request URL',
        1006 => 'no location found for q',
        2006 => 'API key is invalid',
        2007 => 'monthly call quota exceeded',
        2008 => 'API key has been disabled',
        2009 => 'API key has no access to this resource',
        9000 => 'invalid JSON body',
        9001 => 'too many locations in bulk request',
    ];

    public function __construct(
        HttpClient $http,
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.weatherapi.com/v1',
    ) {
        parent::__construct($http);
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function fetch(Location $location): ForecastResult
    {
        if (!$this->isConfigured()) {
            throw new ProviderException('WEATHERAPI_KEY is not set', false);
        }

        [$response, $data] = $this->request(rtrim($this->baseUrl, '/') . '/forecast.json', [
            'key' => $this->apiKey,
            'q' => sprintf('%.5F,%.5F', $location->latitude, $location->longitude),
            'days' => 1, // today only, in the location's timezone
            'aqi' => 'no',
            'alerts' => 'no',
        ]);

        if (is_array($data['error'] ?? null) || !$response->isSuccessful()) {
            throw $this->errorFrom($response->status, $data, $response);
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
        $days = $data['forecast']['forecastday'] ?? null;
        if (!is_array($days) || !array_is_list($days) || $days === []) {
            throw $this->invalidResponse('missing "forecast.forecastday"');
        }

        $tzId = $data['location']['tz_id'] ?? null;
        if (is_string($tzId) && $tzId !== $location->timezone) {
            // Daily rows are aggregated by WeatherAPI in *its* local day. Flag the mismatch.
            $this->warnings[] = sprintf('WeatherAPI reports timezone %s, location is configured as %s', $tzId, $location->timezone);
        }

        $hourly = [];
        $daily = [];
        foreach ($days as $day) {
            $date = $day['date'] ?? null;
            if (!is_string($date) || \DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false) {
                throw $this->invalidResponse('forecastday without a valid "date"');
            }
            $summary = is_array($day['day'] ?? null) ? $day['day'] : [];
            $where = 'daily ' . $date;
            $daily[] = new DailyForecast(
                $date,
                $this->temperature($summary['mintemp_c'] ?? null, $where),
                $this->temperature($summary['maxtemp_c'] ?? null, $where),
                $this->temperature($summary['avgtemp_c'] ?? null, $where),
                $this->precipitation($summary['totalprecip_mm'] ?? null, $where),
            );

            foreach (is_array($day['hour'] ?? null) ? $day['hour'] : [] as $hour) {
                $epoch = $hour['time_epoch'] ?? null;
                if (!is_int($epoch)) {
                    $this->warnings[] = sprintf('%s: hour without "time_epoch" skipped', $where);
                    continue;
                }
                $validAt = (new \DateTimeImmutable('@' . $epoch))->setTimezone(new \DateTimeZone('UTC'));
                $hourWhere = 'hourly ' . $validAt->format('Y-m-d\TH:i\Z');
                $hourly[] = new HourlyForecast(
                    $validAt,
                    $this->temperature($hour['temp_c'] ?? null, $hourWhere),
                    $this->precipitation($hour['precip_mm'] ?? null, $hourWhere),
                );
            }
        }

        if ($hourly === []) {
            throw $this->invalidResponse('no hourly data');
        }

        return new ForecastResult(self::CODE, $hourly, $daily, $rawPayload, $this->takeWarnings());
    }

    /** @param array<mixed>|null $data */
    private function errorFrom(int $status, ?array $data, \WeatherCollector\Http\HttpResponse $response): ProviderException
    {
        $code = $data['error']['code'] ?? null;
        $message = $data['error']['message'] ?? null;
        if (!is_int($code)) {
            return $this->genericHttpError($response);
        }

        $text = sprintf('WeatherAPI error %d: %s', $code, is_string($message) ? $message : (self::PERMANENT_ERROR_CODES[$code] ?? 'unknown'));

        return new ProviderException(
            $this->redact($text),
            !isset(self::PERMANENT_ERROR_CODES[$code]),
            $status,
        );
    }
}
