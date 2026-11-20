<?php

declare(strict_types=1);

namespace WeatherCollector\Http;

final class HttpResponse
{
    /** @param array<string, string> $headers lower-cased header names */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
