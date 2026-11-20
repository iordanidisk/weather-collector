<?php

declare(strict_types=1);

// Use Composer's autoloader when present, otherwise a PSR-4 fallback so the
// app runs without `composer install` (it has no runtime dependencies).
$composer = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'WeatherCollector\\';
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}
