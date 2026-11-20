<?php

declare(strict_types=1);

namespace WeatherCollector\Service;

use WeatherCollector\Logger;
use WeatherCollector\Provider\WeatherProvider;
use WeatherCollector\Repository\FetchRunRepository;
use WeatherCollector\Repository\LocationRepository;

/**
 * Long-running daily scheduler (the `scheduler` container). Wakes up every
 * TICK seconds and runs whatever SchedulePolicy says is due. Stops cleanly on
 * SIGTERM/SIGINT so `docker compose stop` never interrupts a write.
 */
final class Scheduler
{
    private bool $stopping = false;

    /** @param list<WeatherProvider> $providers */
    public function __construct(
        private readonly array $providers,
        private readonly LocationRepository $locations,
        private readonly FetchRunRepository $runs,
        private readonly SchedulePolicy $policy,
        private readonly Collector $collector,
        private readonly Logger $logger,
        private readonly int $tickSeconds = 60,
    ) {
    }

    public function run(): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function (int $signal): void {
                $this->logger->info('Received signal, stopping after current work', ['signal' => $signal]);
                $this->stopping = true;
            };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }

        $closed = $this->runs->failStale(15);
        if ($closed > 0) {
            $this->logger->warning('Closed interrupted runs from a previous process', ['count' => $closed]);
        }

        $enabled = [];
        foreach ($this->providers as $provider) {
            if ($provider->isConfigured()) {
                $enabled[] = $provider;
            } else {
                $this->logger->warning('Provider not configured, skipping', ['provider' => $provider->code()]);
            }
        }
        $this->logger->info('Scheduler started', ['providers' => array_map(static fn ($p) => $p->code(), $enabled)]);

        while (!$this->stopping) {
            try {
                $this->tick($enabled, new \DateTimeImmutable('now'));
            } catch (\Throwable $e) {
                // e.g. database briefly unavailable – log and try again next tick.
                $this->logger->error('Scheduler tick failed', ['error' => $e->getMessage(), 'exception' => get_class($e)]);
            }
            for ($i = 0; $i < $this->tickSeconds && !$this->stopping; $i++) {
                sleep(1);
            }
        }
        $this->logger->info('Scheduler stopped');
    }

    /**
     * @param list<WeatherProvider> $providers
     *
     * @return int number of fetches performed
     */
    public function tick(array $providers, \DateTimeImmutable $now): int
    {
        $performed = 0;
        foreach ($this->locations->active() as $location) {
            foreach ($providers as $provider) {
                if ($this->stopping) {
                    return $performed;
                }
                $decision = $this->policy->check($provider->code(), $location, $now);
                if (!$decision['due']) {
                    continue;
                }
                $this->logger->info('Collecting', ['provider' => $provider->code(), 'location' => $location->slug, 'reason' => $decision['reason']]);
                $this->collector->collect($provider, $location, $now);
                $performed++;
            }
        }

        return $performed;
    }
}
