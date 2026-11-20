<?php

declare(strict_types=1);

namespace WeatherCollector\Tests\Support;

final class SkippedTest extends \RuntimeException
{
}

final class AssertionFailed extends \RuntimeException
{
}

abstract class TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    protected function skip(string $reason): never
    {
        throw new SkippedTest($reason);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(trim($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)));
        }
    }

    protected function assertTrue(bool $condition, string $message = 'Expected condition to be true'): void
    {
        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'Expected condition to be false'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    /**
     * @template T of \Throwable
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function assertThrows(string $class, callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            throw new AssertionFailed(sprintf('Expected %s, got %s: %s', $class, get_class($e), $e->getMessage()));
        }
        throw new AssertionFailed(sprintf('Expected %s to be thrown', $class));
    }

    protected static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/' . $name);
    }
}
