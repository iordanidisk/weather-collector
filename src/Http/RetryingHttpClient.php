<?php

declare(strict_types=1);

namespace WeatherCollector\Http;

use WeatherCollector\Logger;

/**
 * Retries transient failures (network errors, HTTP 429 and 5xx) with
 * exponential backoff. Client errors (4xx) are returned immediately: retrying
 * a bad API key or an exhausted quota only burns more of the quota.
 */
final class RetryingHttpClient implements HttpClient
{
    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /** @param (callable(float): void)|null $sleep seconds; injectable for tests */
    public function __construct(
        private readonly HttpClient $inner,
        private readonly Logger $logger,
        private readonly int $maxRetries = 2,
        private readonly float $baseDelaySeconds = 1.0,
        private readonly float $maxDelaySeconds = 30.0,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep !== null
            ? \Closure::fromCallable($sleep)
            : static fn (float $seconds) => usleep((int) ($seconds * 1_000_000));
    }

    public function get(string $url, array $query = []): HttpResponse
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = $this->inner->get($url, $query);
                if (!self::isTransient($response->status) || $attempt > $this->maxRetries) {
                    return $response;
                }
                $delay = $this->delayFor($attempt, $response->header('retry-after'));
                $reason = 'HTTP ' . $response->status;
            } catch (TransportException $e) {
                if ($attempt > $this->maxRetries) {
                    throw $e;
                }
                $delay = $this->delayFor($attempt, null);
                $reason = $e->getMessage();
            }

            $this->logger->warning('Transient HTTP failure, retrying', [
                'url' => $url,
                'attempt' => $attempt,
                'reason' => $reason,
                'retry_in_s' => round($delay, 2),
            ]);
            ($this->sleep)($delay);
        }
    }

    public static function isTransient(int $status): bool
    {
        return $status === 429 || $status === 408 || $status >= 500;
    }

    private function delayFor(int $attempt, ?string $retryAfter): float
    {
        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            return min((float) $retryAfter, $this->maxDelaySeconds);
        }
        $delay = $this->baseDelaySeconds * (2 ** ($attempt - 1));
        $jitter = $delay * 0.2 * (mt_rand() / mt_getrandmax());

        return min($delay + $jitter, $this->maxDelaySeconds);
    }
}
