<?php

declare(strict_types=1);

namespace WeatherCollector;

/**
 * Immutable application configuration, read from environment variables.
 */
final class Config
{
    /** @param array<string, string> $values */
    private function __construct(private readonly array $values)
    {
    }

    /** @param array<string, string>|null $env */
    public static function fromEnvironment(?array $env = null): self
    {
        $env ??= array_merge($_SERVER, $_ENV, getenv());
        $values = [];
        foreach ($env as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? '';

        return $value === '' ? $default : $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->string($key);
        if ($value === '') {
            return $default;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException(sprintf('Environment variable %s must be an integer, got "%s".', $key, $value));
        }

        return (int) $value;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->string($key);
        if ($value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            ?? throw new \InvalidArgumentException(sprintf('Environment variable %s must be a boolean.', $key));
    }

    public function projectDir(): string
    {
        return dirname(__DIR__);
    }
}
