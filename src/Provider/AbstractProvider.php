<?php

declare(strict_types=1);

namespace WeatherCollector\Provider;

use WeatherCollector\Domain\HourlyForecast;
use WeatherCollector\Http\HttpClient;
use WeatherCollector\Http\HttpResponse;
use WeatherCollector\Http\RetryingHttpClient;
use WeatherCollector\Http\TransportException;

/**
 * Shared plumbing for adapters: HTTP call, JSON decoding and value sanitising.
 */
abstract class AbstractProvider implements WeatherProvider
{
    // Plausibility bounds; values outside are treated as missing (and reported).
    private const TEMPERATURE_RANGE = [-90.0, 60.0];
    private const PRECIPITATION_MAX_MM = 1000.0;

    /** @var list<string> */
    protected array $warnings = [];

    public function __construct(protected readonly HttpClient $http)
    {
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array{0: HttpResponse, 1: array<mixed>|null} response and decoded JSON body (null if not JSON)
     */
    protected function request(string $url, array $query): array
    {
        try {
            $response = $this->http->get($url, $query);
        } catch (TransportException $e) {
            throw new ProviderException($this->redact($e->getMessage()), true, null, $e);
        }

        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        return [$response, is_array($decoded) ? $decoded : null];
    }

    /** Fallback error for non-2xx responses the adapter didn't recognise. */
    protected function genericHttpError(HttpResponse $response, string $detail = ''): ProviderException
    {
        $message = sprintf('%s responded with HTTP %d', $this->code(), $response->status);
        if ($detail !== '') {
            $message .= ': ' . $detail;
        }

        return new ProviderException(
            $this->redact($message),
            RetryingHttpClient::isTransient($response->status),
            $response->status,
        );
    }

    protected function invalidResponse(string $detail, ?int $status = null): ProviderException
    {
        // A malformed payload is usually a transient upstream glitch, so allow a later retry.
        return new ProviderException(sprintf('Unexpected %s response: %s', $this->code(), $detail), true, $status);
    }

    protected function temperature(mixed $value, string $where): ?float
    {
        return $this->number($value, $where, 'temperature', self::TEMPERATURE_RANGE[0], self::TEMPERATURE_RANGE[1]);
    }

    protected function precipitation(mixed $value, string $where): ?float
    {
        return $this->number($value, $where, 'precipitation', 0.0, self::PRECIPITATION_MAX_MM);
    }

    /**
     * Daily mean temperature from the hourly series, for providers that do not
     * supply one. Only computed for complete local days (23–25 h around DST).
     *
     * @param list<HourlyForecast> $hourly
     *
     * @return array<string, float> local date => mean °C
     */
    protected static function dailyMeansFromHourly(array $hourly, \DateTimeZone $tz): array
    {
        $buckets = [];
        foreach ($hourly as $hour) {
            if ($hour->temperatureC === null) {
                continue;
            }
            $buckets[$hour->validAt->setTimezone($tz)->format('Y-m-d')][] = $hour->temperatureC;
        }

        $means = [];
        foreach ($buckets as $date => $values) {
            $expected = self::hoursInLocalDay($date, $tz);
            if (count($values) === $expected) {
                $means[$date] = round(array_sum($values) / count($values), 2);
            }
        }

        return $means;
    }

    protected static function hoursInLocalDay(string $date, \DateTimeZone $tz): int
    {
        $start = new \DateTimeImmutable($date . ' 00:00:00', $tz);

        return (int) (($start->modify('+1 day')->getTimestamp() - $start->getTimestamp()) / 3600);
    }

    protected function redact(string $text): string
    {
        return (string) preg_replace('/([?&]key=)[^&\s]+/i', '$1***', $text);
    }

    /** @return list<string> */
    protected function takeWarnings(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];

        return $warnings;
    }

    private function number(mixed $value, string $where, string $what, float $min, float $max): ?float
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            $this->warnings[] = sprintf('%s: non-numeric %s value %s ignored', $where, $what, json_encode($value));

            return null;
        }
        $number = (float) $value;
        if ($number < $min || $number > $max) {
            $this->warnings[] = sprintf('%s: implausible %s value %s ignored', $where, $what, $number);

            return null;
        }

        return $number;
    }
}
