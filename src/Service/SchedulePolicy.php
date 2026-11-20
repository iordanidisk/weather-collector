<?php

declare(strict_types=1);

namespace WeatherCollector\Service;

use WeatherCollector\Domain\Location;
use WeatherCollector\Repository\FetchRunRepository;

/**
 * Decides whether a provider/location pair should be fetched right now:
 *
 *  - once per local day, at or after FETCH_TIME in the location's own timezone,
 *    collecting that day's forecast;
 *  - after a retryable failure, again after RETRY_INTERVAL_MINUTES,
 *    up to MAX_ATTEMPTS_PER_DAY attempts;
 *  - never again that day after a permanent failure (bad key, quota exhausted),
 *    so a misconfiguration can't drain the free-tier quota.
 *
 * If the service was down at FETCH_TIME it catches up as soon as it starts.
 */
final class SchedulePolicy
{
    private readonly int $fetchMinuteOfDay;

    public function __construct(
        private readonly FetchRunRepository $runs,
        string $fetchTime,
        private readonly int $retryIntervalMinutes,
        private readonly int $maxAttemptsPerDay,
    ) {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $fetchTime, $m)) {
            throw new \InvalidArgumentException(sprintf('FETCH_TIME must be HH:MM, got "%s".', $fetchTime));
        }
        $this->fetchMinuteOfDay = (int) $m[1] * 60 + (int) $m[2];
    }

    /** The day being collected: today at the location. */
    public static function forecastDate(Location $location, \DateTimeImmutable $now): string
    {
        return $now->setTimezone($location->dateTimeZone())->format('Y-m-d');
    }

    /** @return array{due: bool, reason: string} */
    public function check(string $provider, Location $location, \DateTimeImmutable $now): array
    {
        $local = $now->setTimezone($location->dateTimeZone());
        if ((int) $local->format('G') * 60 + (int) $local->format('i') < $this->fetchMinuteOfDay) {
            return ['due' => false, 'reason' => 'before fetch time'];
        }

        $state = $this->runs->dayState($provider, (int) $location->id, self::forecastDate($location, $now));
        if ($state['succeeded']) {
            return ['due' => false, 'reason' => 'already collected today'];
        }
        if ($state['failed_attempts'] === 0) {
            return ['due' => true, 'reason' => 'daily collection'];
        }
        if ($state['last_retryable'] === false) {
            return ['due' => false, 'reason' => 'permanent failure today, waiting for tomorrow'];
        }
        if ($state['failed_attempts'] >= $this->maxAttemptsPerDay) {
            return ['due' => false, 'reason' => 'max attempts reached today'];
        }
        $last = $state['last_started_at'];
        if ($last !== null && $now->getTimestamp() - $last->getTimestamp() < $this->retryIntervalMinutes * 60) {
            return ['due' => false, 'reason' => 'waiting for retry interval'];
        }

        return ['due' => true, 'reason' => sprintf('retry %d/%d', $state['failed_attempts'] + 1, $this->maxAttemptsPerDay)];
    }
}
