<?php

declare(strict_types=1);

namespace WeatherCollector\Tests;

use WeatherCollector\Api\Api;
use WeatherCollector\App;
use WeatherCollector\Config;
use WeatherCollector\Domain\Location;
use WeatherCollector\Http\HttpResponse;
use WeatherCollector\Repository\LocationRepository;
use WeatherCollector\Tests\Support\FakeHttpClient;
use WeatherCollector\Tests\Support\TestCase;

/**
 * End-to-end tests against a real PostgreSQL database with faked provider
 * responses. WARNING: wipes the "public" schema of the TEST_DB_NAME database.
 *
 *   TEST_DB_HOST=localhost TEST_DB_NAME=weather_test TEST_DB_USER=... TEST_DB_PASSWORD=... php tests/run.php
 */
final class IntegrationTest extends TestCase
{
    private FakeHttpClient $http;
    private \DateTimeImmutable $now;
    private string $locationsFile;

    public function setUp(): void
    {
        if (getenv('TEST_DB_HOST') === false) {
            $this->skip('TEST_DB_HOST not set');
        }
        $this->http = new FakeHttpClient();
        $this->now = new \DateTimeImmutable('2026-11-19 07:00', new \DateTimeZone('Europe/Berlin'));
        $this->locationsFile = tempnam(sys_get_temp_dir(), 'loc');
        file_put_contents($this->locationsFile, json_encode([
            ['slug' => 'berlin', 'name' => 'Berlin', 'latitude' => 52.52, 'longitude' => 13.405, 'timezone' => 'Europe/Berlin'],
        ]));

        $app = $this->app();
        $app->pdo()->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
        $this->assertSame(['001_initial_schema'], $app->migrator()->migrate());
        $this->assertSame([], $app->migrator()->migrate(), 'migrations are idempotent');
        $app->locations()->sync(LocationRepository::loadConfigFile($this->locationsFile));
    }

    public function tearDown(): void
    {
        @unlink($this->locationsFile);
    }

    /** @param array<string, string> $env */
    private function app(array $env = []): App
    {
        return new App(Config::fromEnvironment($env + [
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_PORT' => getenv('TEST_DB_PORT') ?: '5432',
            'DB_NAME' => getenv('TEST_DB_NAME') ?: 'weather_test',
            'DB_USER' => getenv('TEST_DB_USER') ?: 'postgres',
            'DB_PASSWORD' => getenv('TEST_DB_PASSWORD') ?: '',
            'DB_CONNECT_ATTEMPTS' => '1',
            'LOCATIONS_FILE' => $this->locationsFile,
            'WEATHERAPI_KEY' => 'test-key',
            'HTTP_MAX_RETRIES' => '0',
            'LOG_LEVEL' => 'error',
        ]), $this->http);
    }

    private function berlin(App $app): Location
    {
        return $app->locations()->findBySlug('berlin') ?? throw new \LogicException('location missing');
    }

    private function queueSuccess(): void
    {
        $this->http
            ->queue('open-meteo', new HttpResponse(200, self::fixture('open-meteo-today.json')))
            ->queue('weatherapi', new HttpResponse(200, self::fixture('weatherapi-forecast.json')));
    }

    private function count(App $app, string $sql): int
    {
        return (int) $app->pdo()->query($sql)->fetchColumn();
    }

    public function testCollectStoresOnlyTodayAndRefetchOverwrites(): void
    {
        $this->queueSuccess();
        $app = $this->app();
        $berlin = $this->berlin($app);

        foreach ($app->providers() as $provider) {
            $this->assertSame('success', $app->collector()->collect($provider, $berlin, $this->now));
        }
        // WeatherAPI's fixture contains 3 days; only today (2026-11-19) is kept.
        $this->assertSame(48, $this->count($app, 'SELECT COUNT(*) FROM hourly_forecasts'));
        $this->assertSame(2, $this->count($app, "SELECT COUNT(*) FROM daily_forecasts WHERE forecast_date = '2026-11-19'"));
        $this->assertSame(2, $this->count($app, 'SELECT COUNT(*) FROM daily_forecasts'));
        $this->assertSame(2, $this->count($app, "SELECT COUNT(*) FROM fetch_runs WHERE status = 'success' AND forecast_date = '2026-11-19' AND raw_payload IS NOT NULL"));

        // Same day again (bin/console fetch --force): rows overwritten, not duplicated.
        foreach ($app->providers() as $provider) {
            $app->collector()->collect($provider, $berlin, $this->now);
        }
        $this->assertSame(48, $this->count($app, 'SELECT COUNT(*) FROM hourly_forecasts'));
        $this->assertSame(2, $this->count($app, "SELECT COUNT(*) FROM fetch_runs WHERE status = 'superseded'"));
        $this->assertSame(2, $this->count($app, "SELECT COUNT(*) FROM fetch_runs WHERE status = 'success'"));
        $this->assertSame(0, $this->count($app, "SELECT COUNT(*) FROM hourly_forecasts h JOIN fetch_runs r ON r.id = h.fetch_run_id WHERE r.status <> 'success'"));

        // Next day: data accumulates day by day.
        $tomorrow = $this->now->modify('+1 day');
        $this->assertSame('success', $app->collector()->collect($app->providers()['weatherapi'], $berlin, $tomorrow));
        $this->assertSame(72, $this->count($app, 'SELECT COUNT(*) FROM hourly_forecasts'));
        $this->assertSame(1, $this->count($app, "SELECT COUNT(*) FROM daily_forecasts WHERE forecast_date = '2026-11-20'"));

        // A response that doesn't contain the requested day is a (retryable) failure.
        $this->assertSame('failed', $app->collector()->collect($app->providers()['open_meteo'], $berlin, $tomorrow));
        $run = $app->pdo()->query("SELECT * FROM fetch_runs WHERE provider = 'open_meteo' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertTrue($run['retryable'] && str_contains($run['error_message'], 'no data for 2026-11-20'));
    }

    public function testFailuresAreRecordedAndDriveTheRetryPolicy(): void
    {
        $this->http
            ->queue('open-meteo', new HttpResponse(503, 'down'))
            ->queue('weatherapi', new HttpResponse(403, '{"error":{"code":2007,"message":"API key has exceeded calls per month quota."}}'));
        $app = $this->app(['RETRY_INTERVAL_MINUTES' => '30', 'FETCH_TIME' => '00:00', 'MAX_ATTEMPTS_PER_DAY' => '2']);
        $berlin = $this->berlin($app);
        $now = new \DateTimeImmutable(); // retry interval is measured against the database clock
        $policy = $app->schedulePolicy();

        $this->assertSame('daily collection', $policy->check('open_meteo', $berlin, $now)['reason']);
        foreach ($app->providers() as $provider) {
            $this->assertSame('failed', $app->collector()->collect($provider, $berlin, $now));
        }
        $this->assertSame(0, $this->count($app, 'SELECT COUNT(*) FROM hourly_forecasts'));

        $wa = $app->pdo()->query("SELECT * FROM fetch_runs WHERE provider = 'weatherapi'")->fetch();
        $this->assertSame('failed', $wa['status']);
        $this->assertFalse($wa['retryable']);
        $this->assertSame(403, $wa['http_status']);

        $this->assertSame('permanent failure today, waiting for tomorrow', $policy->check('weatherapi', $berlin, $now)['reason']);
        $this->assertSame('waiting for retry interval', $policy->check('open_meteo', $berlin, $now)['reason']);

        $eager = $this->app(['RETRY_INTERVAL_MINUTES' => '0', 'FETCH_TIME' => '00:00', 'MAX_ATTEMPTS_PER_DAY' => '2'])->schedulePolicy();
        $this->assertSame('retry 2/2', $eager->check('open_meteo', $berlin, $now)['reason']);
        $app->collector()->collect($app->providers()['open_meteo'], $berlin, $now);
        $this->assertSame('max attempts reached today', $eager->check('open_meteo', $berlin, $now)['reason']);

        // FETCH_TIME is evaluated in the location's timezone.
        $late = $this->app(['FETCH_TIME' => '08:00'])->schedulePolicy();
        $this->assertSame('before fetch time', $late->check('open_meteo', $berlin, $this->now)['reason']);
        $this->assertTrue($late->check('open_meteo', $berlin, $this->now->modify('+1 year +1 hour'))['due']);
    }

    public function testSchedulerTickCollectsOncePerDay(): void
    {
        $this->queueSuccess();
        $app = $this->app(['FETCH_TIME' => '06:00']);
        $scheduler = $app->scheduler();
        $providers = array_values($app->providers());

        $this->assertSame(0, $scheduler->tick($providers, $this->now->setTime(5, 59)));
        $this->assertSame(2, $scheduler->tick($providers, $this->now));
        $this->assertSame(0, $scheduler->tick($providers, $this->now->modify('+5 hours')), 'nothing more the same day');
        $this->assertSame(2, count($this->http->requests));
    }

    public function testApiEndpoints(): void
    {
        $this->queueSuccess();
        $app = $this->app();
        $berlin = $this->berlin($app);
        foreach ($app->providers() as $provider) {
            $app->collector()->collect($provider, $berlin, $this->now);
        }
        $api = new Api($app);

        $daily = $api->handle('GET', '/api/locations/berlin/forecasts/daily', ['from' => '2026-11-01', 'to' => '2026-11-30'], null);
        $this->assertSame(200, $daily->status);
        $this->assertSame(2, $daily->data['count']);
        $first = $daily->data['data'][0];
        $this->assertSame('open_meteo', $first['provider']);
        $this->assertSame('2026-11-19', $first['forecast_date']);
        $this->assertSame(6.0, $first['temperature_max_c']);

        $only = $api->handle('GET', '/api/locations/berlin/forecasts/daily', ['from' => '2026-11-01', 'to' => '2026-11-30', 'provider' => 'weatherapi'], null);
        $this->assertSame(1, $only->data['count']);

        $hourly = $api->handle('GET', '/api/locations/berlin/forecasts/hourly', ['from' => '2026-11-19', 'to' => '2026-11-20', 'provider' => 'weatherapi'], null);
        $this->assertSame(24, $hourly->data['count']);
        $this->assertSame('2026-11-18T23:00:00Z', $hourly->data['data'][0]['valid_at']);
        $this->assertSame(48, $api->handle('GET', '/api/locations/berlin/forecasts/hourly', ['from' => '2026-11-19T00:00+01:00', 'to' => '2026-11-20T00:00+01:00'], null)->data['count']);

        $this->assertSame(1, count($api->handle('GET', '/api/runs', ['provider' => 'open_meteo'], null)->data['data']));
        $this->assertSame(1, count($api->handle('GET', '/api/locations', [], null)->data['data']));
        $this->assertSame(200, $api->handle('GET', '/health', [], null)->status);

        // Validation and errors
        $this->assertSame(404, $api->handle('GET', '/api/locations/atlantis/forecasts/daily', [], null)->status);
        $this->assertSame(400, $api->handle('GET', '/api/locations/berlin/forecasts/daily', ['from' => '2026-13-01'], null)->status);
        $this->assertSame(400, $api->handle('GET', '/api/locations/berlin/forecasts/daily', ['provider' => 'acme'], null)->status);
        $this->assertSame(400, $api->handle('GET', '/api/locations/berlin/forecasts/hourly', ['from' => '2026-01-01', 'to' => '2026-06-01'], null)->status);
        $this->assertSame(404, $api->handle('GET', '/nope', [], null)->status);
        $this->assertSame(405, $api->handle('POST', '/api/locations', [], null)->status);

        // Token auth
        $secured = new Api($this->app(['API_TOKEN' => 's3cret']));
        $this->assertSame(401, $secured->handle('GET', '/api/locations', [], null)->status);
        $this->assertSame(200, $secured->handle('GET', '/api/locations', [], 'Bearer s3cret')->status);
        $this->assertSame(200, $secured->handle('GET', '/health', [], null)->status);
    }
}
