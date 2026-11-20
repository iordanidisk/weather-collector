<?php

declare(strict_types=1);

namespace WeatherCollector;

/**
 * Minimal structured logger: one JSON object per line on stderr, which is
 * what `docker compose logs` collects.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    /** @var resource */
    private $stream;

    private readonly int $threshold;

    /** @param resource|null $stream */
    public function __construct(string $minLevel = 'info', $stream = null)
    {
        $this->threshold = self::LEVELS[strtolower($minLevel)] ?? 1;
        $this->stream = $stream ?? fopen('php://stderr', 'wb');
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context): void
    {
        if (self::LEVELS[$level] < $this->threshold) {
            return;
        }

        $line = json_encode(
            ['time' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => $level, 'message' => $message] + $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        fwrite($this->stream, $line . PHP_EOL);
    }
}
