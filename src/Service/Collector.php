<?php

declare(strict_types=1);

namespace WeatherCollector\Service;

use PDO;
use WeatherCollector\Database\Database;
use WeatherCollector\Domain\DailyForecast;
use WeatherCollector\Domain\ForecastResult;
use WeatherCollector\Domain\HourlyForecast;
use WeatherCollector\Domain\Location;
use WeatherCollector\Logger;
use WeatherCollector\Provider\ProviderException;
use WeatherCollector\Provider\WeatherProvider;
use WeatherCollector\Repository\FetchRunRepository;
use WeatherCollector\Repository\ForecastRepository;

/**
 * Fetches today's forecast (local day at the location) from one provider and
 * stores it. Every attempt is recorded in fetch_runs; one failing pair never
 * affects the others.
 */
final class Collector
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FetchRunRepository $runs,
        private readonly ForecastRepository $forecasts,
        private readonly Logger $logger,
        private readonly bool $storeRawPayload = true,
    ) {
    }

    /** @return 'success'|'failed' */
    public function collect(WeatherProvider $provider, Location $location, ?\DateTimeImmutable $now = null): string
    {
        $date = SchedulePolicy::forecastDate($location, $now ?? new \DateTimeImmutable('now'));
        $context = ['provider' => $provider->code(), 'location' => $location->slug, 'forecast_date' => $date];
        $runId = $this->runs->start($provider->code(), (int) $location->id, $date);
        $context['run_id'] = $runId;

        try {
            $result = self::onlyDate($provider->fetch($location), $date, $location->dateTimeZone());
            if ($result->hourly === [] || $result->daily === []) {
                throw new ProviderException(sprintf('Provider returned no data for %s', $date), true);
            }

            Database::transactional($this->pdo, function () use ($runId, $location, $date, $result, $provider): void {
                $this->runs->supersede($provider->code(), (int) $location->id, $date, $runId);
                $this->forecasts->store($runId, (int) $location->id, $result);
                $this->runs->markSuccess(
                    $runId,
                    count($result->hourly),
                    count($result->daily),
                    $this->storeRawPayload && $result->rawPayload !== '' ? $result->rawPayload : null,
                    $result->warnings !== [] ? 'Warnings: ' . implode('; ', array_slice($result->warnings, 0, 20)) : null,
                );
            });

            foreach (array_slice($result->warnings, 0, 20) as $warning) {
                $this->logger->warning('Data quality warning', $context + ['detail' => $warning]);
            }
            $this->logger->info('Forecast stored', $context + ['hourly' => count($result->hourly), 'daily' => count($result->daily)]);

            return 'success';
        } catch (ProviderException $e) {
            $this->runs->markFailed($runId, $e->getMessage(), $e->retryable, $e->httpStatus);
            $this->logger->error('Provider fetch failed', $context + [
                'error' => $e->getMessage(),
                'retryable' => $e->retryable,
                'http_status' => $e->httpStatus,
            ]);
        } catch (\Throwable $e) {
            // Database errors, bugs, ... – record them and keep going with the next pair.
            $this->runs->markFailed($runId, get_class($e) . ': ' . $e->getMessage(), true, null);
            $this->logger->error('Unexpected error while collecting', $context + ['error' => $e->getMessage(), 'exception' => get_class($e)]);
        }

        return 'failed';
    }

    /**
     * Keeps only the hours and the day belonging to $date (local at the location),
     * in case a provider returns more than the requested single day.
     */
    private static function onlyDate(ForecastResult $result, string $date, \DateTimeZone $tz): ForecastResult
    {
        return new ForecastResult(
            $result->provider,
            array_values(array_filter($result->hourly, static fn (HourlyForecast $h) => $h->validAt->setTimezone($tz)->format('Y-m-d') === $date)),
            array_values(array_filter($result->daily, static fn (DailyForecast $d) => $d->date === $date)),
            $result->rawPayload,
            $result->warnings,
        );
    }
}
