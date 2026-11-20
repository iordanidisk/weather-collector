<?php

declare(strict_types=1);

namespace WeatherCollector\Domain;

final class Location
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $timezone,
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
            throw new \InvalidArgumentException(sprintf('Invalid location slug "%s" (use lowercase letters, digits, "-" and "_").', $slug));
        }
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException(sprintf('Invalid coordinates for location "%s".', $slug));
        }
        new \DateTimeZone($timezone); // throws on an unknown timezone
    }

    public function dateTimeZone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezone);
    }
}
