<?php

declare(strict_types=1);

namespace WeatherCollector\Api;

final class JsonResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly mixed $data,
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public function body(): string
    {
        return json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body();
    }
}
