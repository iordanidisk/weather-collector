<?php

declare(strict_types=1);

namespace WeatherCollector\Database;

use PDO;
use WeatherCollector\Config;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $config->string('DB_HOST', 'localhost'),
            $config->int('DB_PORT', 5432),
            $config->string('DB_NAME', 'weather'),
        );

        $pdo = new PDO($dsn, $config->string('DB_USER', 'weather'), $config->string('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET TIME ZONE 'UTC'");

        return $pdo;
    }

    /** Retries the initial connection, e.g. while the database container is still starting. */
    public static function connectWithRetry(Config $config, int $attempts = 30, int $delaySeconds = 2): PDO
    {
        for ($i = 1; ; $i++) {
            try {
                return self::connect($config);
            } catch (\PDOException $e) {
                if ($i >= $attempts) {
                    throw $e;
                }
                sleep($delaySeconds);
            }
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public static function transactional(PDO $pdo, callable $work): mixed
    {
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
