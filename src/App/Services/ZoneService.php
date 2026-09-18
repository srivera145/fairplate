<?php

namespace Keel\App\Services;

use Keel\App\Models\DeliveryZone;

/**
 * Point-in-zone tests for the delivery area.
 *
 * A radius zone is a great-circle distance check against its centre. A polygon
 * zone is a ray-casting crossing count: walk each edge, count how many cross a
 * ray cast east from the point, and an odd count means inside. Both work on
 * plain lat/lng, which is accurate enough at city scale and needs no spatial
 * extension.
 */
class ZoneService
{
    private const EARTH_RADIUS_METERS = 6371008.8;

    /**
     * True when the point falls inside any active zone.
     */
    public static function contains(float $lat, float $lng): bool
    {
        return self::zoneFor($lat, $lng) !== null;
    }

    /**
     * The first active zone containing the point, or null when none does.
     */
    public static function zoneFor(float $lat, float $lng): ?array
    {
        foreach (DeliveryZone::active() as $zone) {
            if (self::zoneContains($zone, $lat, $lng)) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * True when the point falls inside this one zone.
     */
    public static function zoneContains(array $zone, float $lat, float $lng): bool
    {
        return match ((string) ($zone['type'] ?? '')) {
            'radius' => self::withinRadius($zone, $lat, $lng),
            'polygon' => self::withinPolygon($zone, $lat, $lng),
            default => false,
        };
    }

    /**
     * Great-circle distance in metres between two points.
     */
    public static function haversineMeters(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $fromLatRad = deg2rad($fromLat);
        $toLatRad = deg2rad($toLat);
        $deltaLat = deg2rad($toLat - $fromLat);
        $deltaLng = deg2rad($toLng - $fromLng);

        $a = sin($deltaLat / 2) ** 2
            + cos($fromLatRad) * cos($toLatRad) * sin($deltaLng / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private static function withinRadius(array $zone, float $lat, float $lng): bool
    {
        $centerLat = $zone['center_lat'] ?? null;
        $centerLng = $zone['center_lng'] ?? null;
        $radius = $zone['radius_m'] ?? null;

        if ($centerLat === null || $centerLng === null || $radius === null) {
            return false;
        }

        $distance = self::haversineMeters((float) $centerLat, (float) $centerLng, $lat, $lng);

        return $distance <= (float) $radius;
    }

    private static function withinPolygon(array $zone, float $lat, float $lng): bool
    {
        $vertices = self::vertices($zone['polygon'] ?? null);
        $count = count($vertices);

        if ($count < 3) {
            return false;
        }

        $inside = false;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$iLat, $iLng] = $vertices[$i];
            [$jLat, $jLng] = $vertices[$j];

            // Does the edge straddle the point's latitude, and if so, is the
            // crossing east of the point?
            $straddles = ($iLat > $lat) !== ($jLat > $lat);

            if (!$straddles) {
                continue;
            }

            $crossingLng = $iLng + ($lat - $iLat) / ($jLat - $iLat) * ($jLng - $iLng);

            if ($lng < $crossingLng) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Accepts [[lat, lng], ...] or [{"lat": .., "lng": ..}, ...].
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function vertices(mixed $polygon): array
    {
        if (is_string($polygon)) {
            $polygon = json_decode($polygon, true);
        }

        if (!is_array($polygon)) {
            return [];
        }

        $vertices = [];

        foreach ($polygon as $point) {
            if (is_array($point) && isset($point['lat'], $point['lng'])) {
                $vertices[] = [(float) $point['lat'], (float) $point['lng']];
                continue;
            }

            if (is_array($point) && array_key_exists(0, $point) && array_key_exists(1, $point)) {
                $vertices[] = [(float) $point[0], (float) $point[1]];
            }
        }

        return $vertices;
    }
}
