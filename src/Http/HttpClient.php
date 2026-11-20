<?php

declare(strict_types=1);

namespace WeatherCollector\Http;

interface HttpClient
{
    /**
     * @param array<string, scalar> $query
     *
     * @throws TransportException when no HTTP response could be obtained
     */
    public function get(string $url, array $query = []): HttpResponse;
}
