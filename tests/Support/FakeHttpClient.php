<?php

declare(strict_types=1);

namespace WeatherCollector\Tests\Support;

use WeatherCollector\Http\HttpClient;
use WeatherCollector\Http\HttpResponse;
use WeatherCollector\Http\TransportException;

/**
 * Returns queued responses per URL substring and records every request.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<string, list<HttpResponse|TransportException>> */
    private array $queues = [];

    /** @var list<array{url: string, query: array<string, scalar>}> */
    public array $requests = [];

    public function queue(string $urlContains, HttpResponse|TransportException ...$responses): self
    {
        foreach ($responses as $response) {
            $this->queues[$urlContains][] = $response;
        }

        return $this;
    }

    public function get(string $url, array $query = []): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'query' => $query];
        foreach ($this->queues as $needle => &$queue) {
            if (str_contains($url, $needle) && $queue !== []) {
                $next = count($queue) > 1 ? array_shift($queue) : $queue[0]; // last response repeats
                if ($next instanceof TransportException) {
                    throw $next;
                }

                return $next;
            }
        }
        throw new \LogicException('No fake response queued for ' . $url);
    }
}
