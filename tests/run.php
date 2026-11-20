<?php

declare(strict_types=1);

/*
 * Dependency-free test runner: executes every public test*() method of the
 * classes in tests/*Test.php. Integration tests need a PostgreSQL database
 * (TEST_DB_HOST etc.) and are skipped otherwise.
 *
 *   php tests/run.php [filter]
 */

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/Support/TestCase.php';
require __DIR__ . '/Support/FakeHttpClient.php';

$filter = $argv[1] ?? null;
$passed = $failed = $skipped = 0;

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require_once $file;
    $class = 'WeatherCollector\\Tests\\' . basename($file, '.php');
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test') || ($filter !== null && !str_contains("$class::$method", $filter))) {
            continue;
        }
        $name = basename($file, '.php') . '::' . $method;
        try {
            $test = new $class();
            $test->setUp();
            $test->$method();
            $test->tearDown();
            $passed++;
            echo "  ✓ $name\n";
        } catch (WeatherCollector\Tests\Support\SkippedTest $e) {
            $skipped++;
            echo "  - $name (skipped: {$e->getMessage()})\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  ✗ $name\n      " . get_class($e) . ': ' . $e->getMessage() . "\n      at " . $e->getFile() . ':' . $e->getLine() . "\n";
        }
    }
}

printf("\n%d passed, %d failed, %d skipped\n", $passed, $failed, $skipped);
exit($failed > 0 ? 1 : 0);
