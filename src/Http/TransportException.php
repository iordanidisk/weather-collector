<?php

declare(strict_types=1);

namespace WeatherCollector\Http;

/** Network-level failure: DNS, connection refused, timeout, TLS, ... */
final class TransportException extends \RuntimeException
{
}
