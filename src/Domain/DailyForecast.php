<?php

declare(strict_types=1);

namespace WeatherCollector\Domain;

/**
 * Provider-independent forecast for one local calendar day. Units: °C and mm.
 */
final class DailyForecast
{
    public function __construct(
        public readonly string $date, // Y-m-d, local to the location
        public readonly ?float $temperatureMinC,
        public readonly ?float $temperatureMaxC,
        public readonly ?float $temperatureMeanC,
        public readonly ?float $precipitationMm,
    ) {
    }
}
