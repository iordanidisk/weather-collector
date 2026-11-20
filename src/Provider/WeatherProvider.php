<?php

declare(strict_types=1);

namespace WeatherCollector\Provider;

use WeatherCollector\Domain\ForecastResult;
use WeatherCollector\Domain\Location;

/**
 * Adapter for one upstream weather API. Each adapter is responsible for
 * translating the provider's request/response format, units, time handling
 * and error semantics into the common domain model.
 */
interface WeatherProvider
{
    /** Stable identifier, matches providers.code in the database. */
    public function code(): string;

    /** False when required configuration (e.g. an API key) is missing. */
    public function isConfigured(): bool;

    /** @throws ProviderException */
    public function fetch(Location $location): ForecastResult;
}
