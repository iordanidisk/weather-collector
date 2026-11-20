<?php

declare(strict_types=1);

namespace WeatherCollector\Api;

final class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, $message);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(404, $message);
    }
}
