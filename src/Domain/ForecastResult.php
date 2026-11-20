<?php

declare(strict_types=1);

namespace WeatherCollector\Domain;

/**
 * Normalised result of one provider call.
 */
final class ForecastResult
{
    /**
     * @param list<HourlyForecast> $hourly
     * @param list<DailyForecast>  $daily
     * @param list<string>         $warnings Non-fatal data quality issues found while normalising
     */
    public function __construct(
        public readonly string $provider,
        public readonly array $hourly,
        public readonly array $daily,
        public readonly string $rawPayload,
        public readonly array $warnings = [],
    ) {
    }
}
