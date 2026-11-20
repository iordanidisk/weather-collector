<?php

declare(strict_types=1);

namespace WeatherCollector\Provider;

/**
 * A provider call failed. $retryable tells the scheduler whether trying again
 * later today can help (outage, rate limit) or not (bad key, quota exhausted,
 * invalid request).
 */
final class ProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
