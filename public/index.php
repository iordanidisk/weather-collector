<?php

declare(strict_types=1);

use WeatherCollector\Api\Api;
use WeatherCollector\App;

require dirname(__DIR__) . '/src/bootstrap.php';

$app = App::fromEnvironment();
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$query = array_filter($_GET, 'is_string');

(new Api($app))
    ->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $query, $_SERVER['HTTP_AUTHORIZATION'] ?? null)
    ->send();
