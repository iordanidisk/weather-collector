<?php

declare(strict_types=1);

namespace WeatherCollector\Tests;

use WeatherCollector\Http\HttpResponse;
use WeatherCollector\Http\RetryingHttpClient;
use WeatherCollector\Http\TransportException;
use WeatherCollector\Logger;
use WeatherCollector\Tests\Support\FakeHttpClient;
use WeatherCollector\Tests\Support\TestCase;

final class RetryingHttpClientTest extends TestCase
{
    /** @var list<float> */
    private array $sleeps = [];

    private function client(FakeHttpClient $inner, int $maxRetries = 2): RetryingHttpClient
    {
        $this->sleeps = [];

        return new RetryingHttpClient($inner, new Logger('error'), $maxRetries, 1.0, 30.0, function (float $s): void {
            $this->sleeps[] = $s;
        });
    }

    public function testRetriesTransientFailuresThenSucceeds(): void
    {
        $inner = (new FakeHttpClient())->queue('x', new HttpResponse(503, ''), new TransportException('reset'), new HttpResponse(200, 'ok'));
        $response = $this->client($inner)->get('https://x');
        $this->assertSame(200, $response->status);
        $this->assertSame(3, count($inner->requests));
        $this->assertSame(2, count($this->sleeps));
        $this->assertTrue($this->sleeps[1] >= 2.0, 'exponential backoff');
    }

    public function testDoesNotRetryClientErrors(): void
    {
        $inner = (new FakeHttpClient())->queue('x', new HttpResponse(401, ''));
        $this->assertSame(401, $this->client($inner)->get('https://x')->status);
        $this->assertSame(1, count($inner->requests));
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $inner = (new FakeHttpClient())->queue('x', new HttpResponse(500, ''));
        $this->assertSame(500, $this->client($inner, 2)->get('https://x')->status);
        $this->assertSame(3, count($inner->requests));

        $inner = (new FakeHttpClient())->queue('x', new TransportException('down'));
        $this->assertThrows(TransportException::class, fn () => $this->client($inner, 1)->get('https://x'));
        $this->assertSame(2, count($inner->requests));
    }

    public function testHonoursRetryAfterHeaderWithCap(): void
    {
        $inner = (new FakeHttpClient())->queue('x', new HttpResponse(429, '', ['retry-after' => '120']), new HttpResponse(200, ''));
        $this->client($inner)->get('https://x');
        $this->assertSame([30.0], $this->sleeps);
    }
}
