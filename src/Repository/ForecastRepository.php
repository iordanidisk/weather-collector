<?php

declare(strict_types=1);

namespace WeatherCollector\Repository;

use PDO;
use WeatherCollector\Domain\ForecastResult;

final class ForecastRepository
{
    private const CHUNK = 200;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Stores the day's hourly and daily values. Re-fetching the same day
     * overwrites the existing rows (upsert). Must be called inside a transaction.
     */
    public function store(int $runId, int $locationId, ForecastResult $result): void
    {
        // Providers occasionally repeat a timestamp; keep the last occurrence.
        $hourly = [];
        foreach ($result->hourly as $h) {
            $hourly[$h->validAt->format('Y-m-d\TH:i:s\Z')] = [$h->temperatureC, $h->precipitationMm];
        }
        $daily = [];
        foreach ($result->daily as $d) {
            $daily[$d->date] = [$d->temperatureMinC, $d->temperatureMaxC, $d->temperatureMeanC, $d->precipitationMm];
        }

        foreach (array_chunk($hourly, self::CHUNK, true) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $validAt => [$temp, $precip]) {
                $rows[] = '(?, ?, ?, ?, ?, ?)';
                array_push($params, $runId, $result->provider, $locationId, $validAt, $temp, $precip);
            }
            $this->pdo->prepare(
                'INSERT INTO hourly_forecasts (fetch_run_id, provider, location_id, valid_at, temperature_c, precipitation_mm) VALUES '
                . implode(',', $rows)
                . ' ON CONFLICT (provider, location_id, valid_at) DO UPDATE SET fetch_run_id = EXCLUDED.fetch_run_id,
                    temperature_c = EXCLUDED.temperature_c, precipitation_mm = EXCLUDED.precipitation_mm'
            )->execute($params);
        }

        foreach ($daily as $date => [$min, $max, $mean, $precip]) {
            $this->pdo->prepare(
                'INSERT INTO daily_forecasts (fetch_run_id, provider, location_id, forecast_date,
                    temperature_min_c, temperature_max_c, temperature_mean_c, precipitation_mm)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (provider, location_id, forecast_date) DO UPDATE SET fetch_run_id = EXCLUDED.fetch_run_id,
                    temperature_min_c = EXCLUDED.temperature_min_c, temperature_max_c = EXCLUDED.temperature_max_c,
                    temperature_mean_c = EXCLUDED.temperature_mean_c, precipitation_mm = EXCLUDED.precipitation_mm'
            )->execute([$runId, $result->provider, $locationId, (string) $date, $min, $max, $mean, $precip]);
        }
    }

    /**
     * @param list<string> $providers empty = all
     *
     * @return list<array<string, mixed>>
     */
    public function hourly(int $locationId, array $providers, string $fromUtc, string $toUtc): array
    {
        $params = ['location' => $locationId, 'from' => $fromUtc, 'to' => $toUtc];
        $sql = "SELECT h.provider, to_char(h.valid_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS valid_at,
                       h.temperature_c, h.precipitation_mm
                FROM hourly_forecasts h
                WHERE h.location_id = :location AND h.valid_at >= :from AND h.valid_at < :to"
            . $this->providerFilter('h', $providers, $params)
            . ' ORDER BY h.valid_at, h.provider';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return self::typed($stmt->fetchAll());
    }

    /**
     * @param list<string> $providers empty = all
     *
     * @return list<array<string, mixed>>
     */
    public function daily(int $locationId, array $providers, string $fromDate, string $toDate): array
    {
        $params = ['location' => $locationId, 'from' => $fromDate, 'to' => $toDate];
        $sql = 'SELECT d.provider, d.forecast_date, d.temperature_min_c, d.temperature_max_c,
                       d.temperature_mean_c, d.precipitation_mm
                FROM daily_forecasts d
                WHERE d.location_id = :location AND d.forecast_date BETWEEN :from AND :to'
            . $this->providerFilter('d', $providers, $params)
            . ' ORDER BY d.forecast_date, d.provider';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return self::typed($stmt->fetchAll());
    }

    private const FLOAT_COLUMNS = ['temperature_c', 'precipitation_mm', 'temperature_min_c', 'temperature_max_c', 'temperature_mean_c'];

    /**
     * pdo_pgsql returns numeric columns as strings; convert for JSON output.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function typed(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach (self::FLOAT_COLUMNS as $column) {
                if (array_key_exists($column, $row) && $row[$column] !== null) {
                    $row[$column] = (float) $row[$column];
                }
            }
        }

        return $rows;
    }

    /**
     * @param list<string>         $providers
     * @param array<string, mixed> $params
     */
    private function providerFilter(string $alias, array $providers, array &$params): string
    {
        if ($providers === []) {
            return '';
        }
        $names = [];
        foreach (array_values($providers) as $i => $provider) {
            $names[] = ':p' . $i;
            $params['p' . $i] = $provider;
        }

        return " AND $alias.provider IN (" . implode(',', $names) . ')';
    }
}
