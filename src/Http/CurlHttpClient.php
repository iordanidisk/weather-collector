<?php

declare(strict_types=1);

namespace WeatherCollector\Http;

final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 15,
        private readonly string $userAgent = 'weather-collector/1.0',
    ) {
    }

    public function get(string $url, array $query = []): HttpResponse
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_ENCODING => '', // accept gzip/deflate
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);
            curl_close($handle);
            throw new TransportException(sprintf('HTTP request failed (curl error %d: %s)', $errno, $error));
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($status, (string) $body, $headers);
    }
}
