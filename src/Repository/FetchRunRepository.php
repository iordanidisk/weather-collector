<?php

declare(strict_types=1);

namespace WeatherCollector\Repository;

use PDO;

final class FetchRunRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function start(string $provider, int $locationId, string $forecastDate): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO fetch_runs (provider, location_id, forecast_date, status)
             VALUES (?, ?, ?, 'running') RETURNING id"
        );
        $stmt->execute([$provider, $locationId, $forecastDate]);

        return (int) $stmt->fetchColumn();
    }

    /** Marks an earlier successful run for the same day as superseded (used by --force re-fetches). */
    public function supersede(string $provider, int $locationId, string $forecastDate, int $exceptRunId): void
    {
        $this->pdo->prepare(
            "UPDATE fetch_runs SET status = 'superseded'
             WHERE provider = ? AND location_id = ? AND forecast_date = ? AND status = 'success' AND id <> ?"
        )->execute([$provider, $locationId, $forecastDate, $exceptRunId]);
    }

    public function markSuccess(int $runId, int $hourlyCount, int $dailyCount, ?string $rawPayload, ?string $warnings): void
    {
        $this->pdo->prepare(
            "UPDATE fetch_runs SET status = 'success', finished_at = NOW(),
                duration_ms = (EXTRACT(EPOCH FROM (NOW() - started_at)) * 1000)::int,
                http_status = 200, hourly_count = ?, daily_count = ?, raw_payload = CAST(? AS jsonb),
                error_message = ?
             WHERE id = ?"
        )->execute([$hourlyCount, $dailyCount, $rawPayload, $warnings, $runId]);
    }

    public function markFailed(int $runId, string $error, bool $retryable, ?int $httpStatus): void
    {
        $this->pdo->prepare(
            "UPDATE fetch_runs SET status = 'failed', finished_at = NOW(),
                duration_ms = (EXTRACT(EPOCH FROM (NOW() - started_at)) * 1000)::int,
                error_message = ?, retryable = ?, http_status = ?
             WHERE id = ?"
        )->execute([mb_substr($error, 0, 2000), $retryable ? 'true' : 'false', $httpStatus, $runId]);
    }

    /**
     * Runs left in "running" by a crashed process are closed as retryable failures.
     *
     * @return int number of runs closed
     */
    public function failStale(int $olderThanMinutes): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE fetch_runs SET status = 'failed', finished_at = NOW(), retryable = TRUE,
                error_message = 'Run was interrupted (process stopped before finishing)'
             WHERE status = 'running' AND started_at < NOW() - make_interval(mins => ?)"
        );
        $stmt->execute([$olderThanMinutes]);

        return $stmt->rowCount();
    }

    /**
     * Summary of today's attempts for one provider/location, used by the
     * scheduler to decide whether to (re)try.
     *
     * @return array{succeeded: bool, failed_attempts: int, last_started_at: ?\DateTimeImmutable, last_retryable: ?bool}
     */
    public function dayState(string $provider, int $locationId, string $forecastDate): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                BOOL_OR(status = 'success') AS succeeded,
                COUNT(*) FILTER (WHERE status = 'failed') AS failed_attempts,
                MAX(started_at) AS last_started_at,
                (ARRAY_AGG(retryable ORDER BY started_at DESC) FILTER (WHERE status = 'failed'))[1] AS last_retryable
             FROM fetch_runs
             WHERE provider = ? AND location_id = ? AND forecast_date = ? AND status <> 'superseded'"
        );
        $stmt->execute([$provider, $locationId, $forecastDate]);
        $row = $stmt->fetch();

        return [
            'succeeded' => (bool) $row['succeeded'],
            'failed_attempts' => (int) $row['failed_attempts'],
            'last_started_at' => $row['last_started_at'] !== null ? new \DateTimeImmutable($row['last_started_at']) : null,
            'last_retryable' => $row['last_retryable'] === null ? null : (bool) $row['last_retryable'],
        ];
    }

    /**
     * @param array{provider?: string, location?: string, status?: string} $filters
     *
     * @return list<array<string, mixed>>
     */
    public function recent(array $filters, int $limit): array
    {
        $where = [];
        $params = [];
        if (isset($filters['provider'])) {
            $where[] = 'r.provider = :provider';
            $params['provider'] = $filters['provider'];
        }
        if (isset($filters['location'])) {
            $where[] = 'l.slug = :location';
            $params['location'] = $filters['location'];
        }
        if (isset($filters['status'])) {
            $where[] = 'r.status = :status';
            $params['status'] = $filters['status'];
        }
        $sql = 'SELECT r.id, r.provider, l.slug AS location, r.forecast_date, r.status, r.started_at, r.finished_at,
                       r.duration_ms, r.http_status, r.retryable, r.error_message, r.hourly_count, r.daily_count
                FROM fetch_runs r JOIN locations l ON l.id = r.location_id'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY r.started_at DESC, r.id DESC LIMIT ' . max(1, min($limit, 500));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> latest run per provider/location */
    public function latestPerPair(): array
    {
        return $this->pdo->query(
            "SELECT DISTINCT ON (r.provider, r.location_id)
                    r.provider, l.slug AS location, r.forecast_date, r.status, r.started_at, r.error_message,
                    (SELECT MAX(s.forecast_date) FROM fetch_runs s
                      WHERE s.provider = r.provider AND s.location_id = r.location_id AND s.status = 'success') AS last_success_date
             FROM fetch_runs r JOIN locations l ON l.id = r.location_id
             WHERE l.active
             ORDER BY r.provider, r.location_id, r.started_at DESC"
        )->fetchAll();
    }
}
