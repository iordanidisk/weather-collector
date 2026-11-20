<?php

declare(strict_types=1);

namespace WeatherCollector\Tests;

use WeatherCollector\Domain\Location;
use WeatherCollector\Http\HttpResponse;
use WeatherCollector\Http\TransportException;
use WeatherCollector\Provider\OpenMeteoProvider;
use WeatherCollector\Provider\ProviderException;
use WeatherCollector\Provider\WeatherApiProvider;
use WeatherCollector\Tests\Support\FakeHttpClient;
use WeatherCollector\Tests\Support\TestCase;

final class ProvidersTest extends TestCase
{
    private Location $berlin;

    public function setUp(): void
    {
        $this->berlin = new Location(1, 'berlin', 'Berlin', 52.52, 13.405, 'Europe/Berlin');
    }

    public function testOpenMeteoRequestsOnlyToday(): void
    {
        $http = (new FakeHttpClient())->queue('open-meteo', new HttpResponse(200, self::fixture('open-meteo-today.json')));
        $result = (new OpenMeteoProvider($http))->fetch($this->berlin);

        $query = $http->requests[0]['query'];
        $this->assertSame(1, $query['forecast_days']);
        $this->assertSame('unixtime', $query['timeformat']);
        $this->assertSame('Europe/Berlin', $query['timezone']);

        $this->assertSame(24, count($result->hourly));
        $this->assertSame('2026-11-18T23:00:00Z', $result->hourly[0]->validAt->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(1, count($result->daily));
        $this->assertSame('2026-11-19', $result->daily[0]->date);
        $this->assertSame(0.1, $result->daily[0]->temperatureMinC);
        $this->assertTrue(is_float($result->daily[0]->temperatureMeanC), 'mean computed from 24 hours');
    }

    public function testOpenMeteoNormalisationAcrossDstChange(): void
    {
        // The parser is robust to longer responses; this fixture spans the 25-hour day on 2026-10-25.
        $data = json_decode(self::fixture('open-meteo-dst-multiday.json'), true);
        $result = (new OpenMeteoProvider(new FakeHttpClient()))->parse($data, $this->berlin);

        // 24 + 25 (DST ends on 2026-10-25) + 24 hours
        $this->assertSame(73, count($result->hourly));
        $this->assertSame('2026-10-23T22:00:00Z', $result->hourly[0]->validAt->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(['2026-10-24', '2026-10-25', '2026-10-26'], array_map(static fn ($d) => $d->date, $result->daily));
        $this->assertSame(13.0, $result->daily[0]->temperatureMaxC);
        $this->assertSame(1.6, $result->daily[0]->precipitationMm);

        // Day 1 has a missing hour -> no mean; day 2 has 25 complete hours -> mean computed.
        $this->assertSame(null, $result->daily[0]->temperatureMeanC);
        $this->assertTrue(is_float($result->daily[1]->temperatureMeanC), 'mean for the 25h day');

        $this->assertSame(null, $result->hourly[5]->temperatureC);
        $this->assertSame(null, $result->hourly[30]->precipitationMm, 'negative precipitation dropped');
        $this->assertSame(1, count($result->warnings));
    }

    public function testOpenMeteoClientErrorIsPermanent(): void
    {
        $http = (new FakeHttpClient())->queue('open-meteo', new HttpResponse(400, '{"error":true,"reason":"Latitude must be in range of -90 to 90°."}'));
        $e = $this->assertThrows(ProviderException::class, fn () => (new OpenMeteoProvider($http))->fetch($this->berlin));
        $this->assertFalse($e->retryable);
        $this->assertSame(400, $e->httpStatus);
        $this->assertTrue(str_contains($e->getMessage(), 'Latitude must be in range'));
    }

    public function testOpenMeteoServerErrorAndBadPayloadAreRetryable(): void
    {
        $http = (new FakeHttpClient())->queue('open-meteo', new HttpResponse(503, 'Service Unavailable'));
        $this->assertTrue($this->assertThrows(ProviderException::class, fn () => (new OpenMeteoProvider($http))->fetch($this->berlin))->retryable);

        $http = (new FakeHttpClient())->queue('open-meteo', new HttpResponse(200, '<html>oops</html>'));
        $this->assertTrue($this->assertThrows(ProviderException::class, fn () => (new OpenMeteoProvider($http))->fetch($this->berlin))->retryable);

        $http = (new FakeHttpClient())->queue('open-meteo', new TransportException('timeout'));
        $this->assertTrue($this->assertThrows(ProviderException::class, fn () => (new OpenMeteoProvider($http))->fetch($this->berlin))->retryable);
    }

    public function testOpenMeteoRejectsInconsistentSeries(): void
    {
        $data = json_decode(self::fixture('open-meteo-today.json'), true);
        array_pop($data['hourly']['temperature_2m']);
        $this->assertThrows(ProviderException::class, fn () => (new OpenMeteoProvider(new FakeHttpClient()))->parse($data, $this->berlin));

        $data = json_decode(self::fixture('open-meteo-today.json'), true);
        unset($data['daily']);
        $this->assertThrows(ProviderException::class, fn () => (new OpenMeteoProvider(new FakeHttpClient()))->parse($data, $this->berlin));
    }

    public function testWeatherApiNormalisation(): void
    {
        $http = (new FakeHttpClient())->queue('weatherapi', new HttpResponse(200, self::fixture('weatherapi-forecast.json')));
        $result = (new WeatherApiProvider($http, 'secret'))->fetch($this->berlin);

        $query = $http->requests[0]['query'];
        $this->assertSame('52.52000,13.40500', $query['q']);
        $this->assertSame(1, $query['days']);

        $this->assertSame(72, count($result->hourly));
        $this->assertSame('2026-11-18T23:00:00Z', $result->hourly[0]->validAt->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(3, count($result->daily));
        $this->assertSame('2026-11-19', $result->daily[0]->date);
        $this->assertSame(1.0, $result->daily[0]->temperatureMinC);
        $this->assertSame(7.0, $result->daily[0]->temperatureMaxC);
        $this->assertSame(4.0, $result->daily[0]->temperatureMeanC);
        $this->assertSame(0.3, $result->daily[0]->precipitationMm);
        $this->assertSame([], $result->warnings);
    }

    public function testWeatherApiQuotaErrorIsPermanentAndKeyIsRedacted(): void
    {
        $http = (new FakeHttpClient())->queue('weatherapi', new HttpResponse(403, '{"error":{"code":2007,"message":"API key has exceeded calls per month quota."}}'));
        $e = $this->assertThrows(ProviderException::class, fn () => (new WeatherApiProvider($http, 'secret'))->fetch($this->berlin));
        $this->assertFalse($e->retryable);
        $this->assertSame(403, $e->httpStatus);
        $this->assertFalse(str_contains($e->getMessage(), 'secret'));

        $http = (new FakeHttpClient())->queue('weatherapi', new TransportException('Could not resolve host for https://api.weatherapi.com/v1/forecast.json?key=secret&q=1'));
        $e = $this->assertThrows(ProviderException::class, fn () => (new WeatherApiProvider($http, 'secret'))->fetch($this->berlin));
        $this->assertTrue($e->retryable);
        $this->assertFalse(str_contains($e->getMessage(), 'secret'), 'key redacted from transport errors');
    }

    public function testWeatherApiInternalErrorIsRetryable(): void
    {
        $http = (new FakeHttpClient())->queue('weatherapi', new HttpResponse(400, '{"error":{"code":9999,"message":"Internal application error."}}'));
        $this->assertTrue($this->assertThrows(ProviderException::class, fn () => (new WeatherApiProvider($http, 'k'))->fetch($this->berlin))->retryable);
    }

    public function testWeatherApiWithoutKeyIsNotConfigured(): void
    {
        $provider = new WeatherApiProvider(new FakeHttpClient(), '');
        $this->assertFalse($provider->isConfigured());
        $this->assertFalse($this->assertThrows(ProviderException::class, fn () => $provider->fetch($this->berlin))->retryable);
    }
}
