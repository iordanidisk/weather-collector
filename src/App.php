<?php

declare(strict_types=1);

namespace WeatherCollector;

use PDO;
use WeatherCollector\Database\Database;
use WeatherCollector\Database\Migrator;
use WeatherCollector\Http\CurlHttpClient;
use WeatherCollector\Http\HttpClient;
use WeatherCollector\Http\RetryingHttpClient;
use WeatherCollector\Provider\OpenMeteoProvider;
use WeatherCollector\Provider\WeatherApiProvider;
use WeatherCollector\Provider\WeatherProvider;
use WeatherCollector\Repository\FetchRunRepository;
use WeatherCollector\Repository\ForecastRepository;
use WeatherCollector\Repository\LocationRepository;
use WeatherCollector\Service\Collector;
use WeatherCollector\Service\SchedulePolicy;
use WeatherCollector\Service\Scheduler;

/**
 * Tiny hand-wired service container.
 */
final class App
{
    private ?PDO $pdo = null;
    private ?Logger $logger = null;
    /** @var array<string, WeatherProvider>|null */
    private ?array $providers = null;

    public function __construct(
        public readonly Config $config,
        private ?HttpClient $httpTransport = null, // injectable for tests
    ) {
    }

    public static function fromEnvironment(): self
    {
        $app = new self(Config::fromEnvironment());
        date_default_timezone_set('UTC');

        return $app;
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger($this->config->string('LOG_LEVEL', 'info'));
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= Database::connectWithRetry($this->config, $this->config->int('DB_CONNECT_ATTEMPTS', 30));
    }

    public function migrator(): Migrator
    {
        return new Migrator($this->pdo(), $this->config->projectDir() . '/migrations', $this->logger());
    }

    public function locations(): LocationRepository
    {
        return new LocationRepository($this->pdo());
    }

    public function runs(): FetchRunRepository
    {
        return new FetchRunRepository($this->pdo());
    }

    public function forecasts(): ForecastRepository
    {
        return new ForecastRepository($this->pdo());
    }

    public function locationsFile(): string
    {
        $path = $this->config->string('LOCATIONS_FILE', 'config/locations.json');

        return str_starts_with($path, '/') ? $path : $this->config->projectDir() . '/' . $path;
    }

    /** @return array<string, WeatherProvider> */
    public function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $http = new RetryingHttpClient(
            $this->httpTransport ?? new CurlHttpClient($this->config->int('HTTP_TIMEOUT_SECONDS', 15)),
            $this->logger(),
            $this->config->int('HTTP_MAX_RETRIES', 2),
            (float) $this->config->int('HTTP_RETRY_BASE_DELAY_MS', 1000) / 1000,
        );

        $providers = [
            new OpenMeteoProvider(
                $http,
                $this->config->string('OPEN_METEO_BASE_URL', 'https://api.open-meteo.com/v1'),
            ),
            new WeatherApiProvider(
                $http,
                $this->config->string('WEATHERAPI_KEY'),
                $this->config->string('WEATHERAPI_BASE_URL', 'https://api.weatherapi.com/v1'),
            ),
        ];

        $enabled = array_filter(array_map('trim', explode(',', $this->config->string('PROVIDERS', 'open_meteo,weatherapi'))));
        $this->providers = [];
        foreach ($providers as $provider) {
            if (in_array($provider->code(), $enabled, true)) {
                $this->providers[$provider->code()] = $provider;
            }
        }

        return $this->providers;
    }

    public function schedulePolicy(): SchedulePolicy
    {
        return new SchedulePolicy(
            $this->runs(),
            $this->config->string('FETCH_TIME', '06:00'),
            $this->config->int('RETRY_INTERVAL_MINUTES', 30),
            $this->config->int('MAX_ATTEMPTS_PER_DAY', 4),
        );
    }

    public function collector(): Collector
    {
        return new Collector($this->pdo(), $this->runs(), $this->forecasts(), $this->logger(), $this->config->bool('STORE_RAW_PAYLOAD', true));
    }

    public function scheduler(): Scheduler
    {
        return new Scheduler(
            array_values($this->providers()),
            $this->locations(),
            $this->runs(),
            $this->schedulePolicy(),
            $this->collector(),
            $this->logger(),
            $this->config->int('SCHEDULER_TICK_SECONDS', 60),
        );
    }
}
