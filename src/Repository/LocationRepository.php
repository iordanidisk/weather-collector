<?php

declare(strict_types=1);

namespace WeatherCollector\Repository;

use PDO;
use WeatherCollector\Domain\Location;

final class LocationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Makes the database match the configured list: upserts every configured
     * location and deactivates the rest (their history is kept).
     *
     * @param list<Location> $locations
     */
    public function sync(array $locations): void
    {
        $upsert = $this->pdo->prepare(
            'INSERT INTO locations (slug, name, latitude, longitude, timezone, active)
             VALUES (:slug, :name, :lat, :lon, :tz, TRUE)
             ON CONFLICT (slug) DO UPDATE SET
                name = EXCLUDED.name, latitude = EXCLUDED.latitude, longitude = EXCLUDED.longitude,
                timezone = EXCLUDED.timezone, active = TRUE, updated_at = NOW()'
        );
        foreach ($locations as $location) {
            $upsert->execute([
                'slug' => $location->slug,
                'name' => $location->name,
                'lat' => $location->latitude,
                'lon' => $location->longitude,
                'tz' => $location->timezone,
            ]);
        }

        $slugs = array_map(static fn (Location $l) => $l->slug, $locations);
        if ($slugs === []) {
            $this->pdo->exec('UPDATE locations SET active = FALSE, updated_at = NOW() WHERE active');

            return;
        }
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $this->pdo->prepare("UPDATE locations SET active = FALSE, updated_at = NOW() WHERE active AND slug NOT IN ($placeholders)")
            ->execute($slugs);
    }

    /** @return list<Location> */
    public function active(): array
    {
        $rows = $this->pdo->query('SELECT * FROM locations WHERE active ORDER BY slug')->fetchAll();

        return array_map(self::hydrate(...), $rows);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT slug, name, latitude, longitude, timezone, active FROM locations ORDER BY slug')->fetchAll();

        return array_map(static fn (array $r) => ['latitude' => (float) $r['latitude'], 'longitude' => (float) $r['longitude']] + $r, $rows);
    }

    public function findBySlug(string $slug): ?Location
    {
        $stmt = $this->pdo->prepare('SELECT * FROM locations WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Location
    {
        return new Location(
            (int) $row['id'],
            (string) $row['slug'],
            (string) $row['name'],
            (float) $row['latitude'],
            (float) $row['longitude'],
            (string) $row['timezone'],
        );
    }

    /**
     * Reads the locations config file.
     *
     * @return list<Location>
     */
    public static function loadConfigFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Locations file "%s" not found.', $path));
        }
        $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !array_is_list($data)) {
            throw new \RuntimeException('Locations file must contain a JSON array of locations.');
        }

        $locations = [];
        foreach ($data as $i => $item) {
            foreach (['slug', 'name', 'latitude', 'longitude', 'timezone'] as $key) {
                if (!isset($item[$key])) {
                    throw new \RuntimeException(sprintf('Location #%d in %s is missing "%s".', $i, $path, $key));
                }
            }
            $locations[] = new Location(null, (string) $item['slug'], (string) $item['name'], (float) $item['latitude'], (float) $item['longitude'], (string) $item['timezone']);
        }

        $slugs = array_map(static fn (Location $l) => $l->slug, $locations);
        if (count($slugs) !== count(array_unique($slugs))) {
            throw new \RuntimeException('Location slugs must be unique.');
        }

        return $locations;
    }
}
