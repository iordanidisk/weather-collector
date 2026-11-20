<?php

declare(strict_types=1);

namespace WeatherCollector\Domain;

/**
 * Provider-independent forecast for one hour. Units: °C and mm.
 * $validAt is the start of the hour, in UTC.
 */
final class HourlyForecast
{
    public function __construct(
        public readonly \DateTimeImmutable $validAt,
        public readonly ?float $temperatureC,
        public readonly ?float $precipitationMm,
    ) {
    }
}
