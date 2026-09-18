<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Models\DeliveryZone;
use Keel\App\Services\ZoneService;
use PHPUnit\Framework\TestCase;

/**
 * The geometry on its own, with zones handed in as arrays so nothing here
 * touches a database.
 */
class ZoneServiceTest extends TestCase
{
    private const DOWNTOWN = [30.4383, -84.2807];

    private function tallahasseeRadiusZone(): array
    {
        return [
            'type' => DeliveryZone::TYPE_RADIUS,
            'center_lat' => '30.4383000',
            'center_lng' => '-84.2807000',
            'radius_m' => 12000,
        ];
    }

    public function testDowntownIsInsideTheRadiusZone(): void
    {
        self::assertTrue(
            ZoneService::zoneContains($this->tallahasseeRadiusZone(), self::DOWNTOWN[0], self::DOWNTOWN[1])
        );
    }

    public function testAPointThirtyKilometresAwayIsOutsideTheRadiusZone(): void
    {
        // Due north of downtown by 30 km.
        $lat = self::DOWNTOWN[0] + (30000 / 111320);

        self::assertFalse(
            ZoneService::zoneContains($this->tallahasseeRadiusZone(), $lat, self::DOWNTOWN[1])
        );
    }

    public function testTheRadiusEdgeIsInclusive(): void
    {
        $zone = $this->tallahasseeRadiusZone();

        $justInside = self::DOWNTOWN[0] + (11900 / 111320);
        $justOutside = self::DOWNTOWN[0] + (12100 / 111320);

        self::assertTrue(ZoneService::zoneContains($zone, $justInside, self::DOWNTOWN[1]));
        self::assertFalse(ZoneService::zoneContains($zone, $justOutside, self::DOWNTOWN[1]));
    }

    public function testHaversineMatchesAKnownDistance(): void
    {
        // Tallahassee to Jacksonville is roughly 264 km.
        $meters = ZoneService::haversineMeters(30.4383, -84.2807, 30.3322, -81.6557);

        self::assertGreaterThan(250000, $meters);
        self::assertLessThan(275000, $meters);
    }

    public function testPolygonZoneUsesRayCasting(): void
    {
        // A square box around downtown, given as [lat, lng] pairs.
        $zone = [
            'type' => DeliveryZone::TYPE_POLYGON,
            'polygon' => json_encode([
                [30.40, -84.35],
                [30.48, -84.35],
                [30.48, -84.20],
                [30.40, -84.20],
            ]),
        ];

        self::assertTrue(ZoneService::zoneContains($zone, self::DOWNTOWN[0], self::DOWNTOWN[1]));
        self::assertFalse(ZoneService::zoneContains($zone, 30.60, -84.28), 'north of the box');
        self::assertFalse(ZoneService::zoneContains($zone, 30.44, -84.50), 'west of the box');
    }

    public function testPolygonAcceptsObjectVertices(): void
    {
        $zone = [
            'type' => DeliveryZone::TYPE_POLYGON,
            'polygon' => json_encode([
                ['lat' => 30.40, 'lng' => -84.35],
                ['lat' => 30.48, 'lng' => -84.35],
                ['lat' => 30.48, 'lng' => -84.20],
                ['lat' => 30.40, 'lng' => -84.20],
            ]),
        ];

        self::assertTrue(ZoneService::zoneContains($zone, self::DOWNTOWN[0], self::DOWNTOWN[1]));
    }

    public function testAConcavePolygonExcludesItsNotch(): void
    {
        // A C shape opening east: the notch must read as outside.
        $zone = [
            'type' => DeliveryZone::TYPE_POLYGON,
            'polygon' => json_encode([
                [0.0, 0.0], [0.0, 4.0], [4.0, 4.0], [4.0, 0.0],
                [3.0, 0.0], [3.0, 3.0], [1.0, 3.0], [1.0, 0.0],
            ]),
        ];

        self::assertTrue(ZoneService::zoneContains($zone, 3.5, 2.0), 'solid part');
        self::assertFalse(ZoneService::zoneContains($zone, 2.0, 1.0), 'inside the notch');
    }

    public function testIncompleteZonesAreNeverInside(): void
    {
        self::assertFalse(ZoneService::zoneContains(['type' => 'radius'], 30.4, -84.2));
        self::assertFalse(ZoneService::zoneContains(['type' => 'polygon', 'polygon' => '[]'], 30.4, -84.2));
        self::assertFalse(ZoneService::zoneContains(['type' => 'nonsense'], 30.4, -84.2));
    }
}
