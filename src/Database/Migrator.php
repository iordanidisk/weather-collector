<?php

declare(strict_types=1);

namespace WeatherCollector\Database;

use PDO;
use WeatherCollector\Logger;

/**
 * Applies migrations/NNN_*.sql in order, each in its own transaction, and
 * records them in schema_migrations. Safe to run on every container start;
 * an advisory lock prevents two containers migrating concurrently.
 */
final class Migrator
{
    private const LOCK_ID = 727274001;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
        private readonly Logger $logger,
    ) {
    }

    /** @return list<string> applied migration names */
    public function migrate(): array
    {
        $this->pdo->exec('SELECT pg_advisory_lock(' . self::LOCK_ID . ')');
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations (
                    version VARCHAR(255) PRIMARY KEY,
                    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )'
            );
            $done = $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

            $files = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
            sort($files, SORT_STRING);

            $applied = [];
            foreach ($files as $file) {
                $version = basename($file, '.sql');
                if (in_array($version, $done, true)) {
                    continue;
                }
                Database::transactional($this->pdo, function () use ($file, $version): void {
                    $this->pdo->exec((string) file_get_contents($file));
                    $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
                });
                $this->logger->info('Applied migration', ['version' => $version]);
                $applied[] = $version;
            }

            return $applied;
        } finally {
            $this->pdo->exec('SELECT pg_advisory_unlock(' . self::LOCK_ID . ')');
        }
    }
}
