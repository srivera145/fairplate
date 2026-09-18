<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Services\RestaurantHours;
use PHPUnit\Framework\TestCase;

/**
 * Hours are a JSON blob two phases write and three read, so the shape and the
 * "are we open" answer are both worth pinning down here rather than finding out
 * from a customer who ordered at four in the morning.
 */
class RestaurantHoursTest extends TestCase
{
    private const ZONE = 'America/New_York';

    public function testThePhaseTwoShapeStillReads(): void
    {
        // What the seeder wrote: seven day keys, one range each, no wrapper.
        $legacy = json_encode(['mon' => ['open' => '11:00', 'close' => '22:00']]);

        $hours = RestaurantHours::normalize($legacy);

        self::assertSame([['open' => '11:00', 'close' => '22:00']], $hours['days']['mon']);
        self::assertSame([], $hours['days']['tue']);
        self::assertSame([], $hours['closures']);
    }

    public function testADayMayHoldSeveralRanges(): void
    {
        $hours = RestaurantHours::normalize([
            'days' => [
                'wed' => [
                    ['open' => '17:00', 'close' => '22:00'],
                    ['open' => '11:00', 'close' => '14:00'],
                ],
            ],
        ]);

        // Sorted, so the editor and the menu both read them in the order of the
        // day rather than the order they happened to be typed.
        self::assertSame(
            [['open' => '11:00', 'close' => '14:00'], ['open' => '17:00', 'close' => '22:00']],
            $hours['days']['wed']
        );
    }

    public function testNonsenseRangesAreDropped(): void
    {
        $hours = RestaurantHours::normalize([
            'days' => [
                'thu' => [
                    ['open' => '11:00', 'close' => '11:00'],
                    ['open' => 'lunchtime', 'close' => '14:00'],
                    ['open' => '17:00', 'close' => '22:00'],
                ],
            ],
        ]);

        self::assertSame([['open' => '17:00', 'close' => '22:00']], $hours['days']['thu']);
    }

    public function testOpenAndClosedWithinTheDay(): void
    {
        $hours = ['days' => ['wed' => [['open' => '11:00', 'close' => '14:00']]]];

        self::assertTrue(RestaurantHours::isOpenAt($hours, $this->at('2026-09-16 12:30')));
        self::assertFalse(RestaurantHours::isOpenAt($hours, $this->at('2026-09-16 15:30')));
        self::assertFalse(RestaurantHours::isOpenAt($hours, $this->at('2026-09-16 10:59')));
    }

    public function testTheSplitShiftIsClosedInBetween(): void
    {
        $hours = ['days' => ['wed' => [
            ['open' => '11:00', 'close' => '14:00'],
            ['open' => '17:00', 'close' => '22:00'],
        ]]];

        self::assertTrue(RestaurantHours::isOpenAt($hours, $this->at('2026-09-16 11:30')));
        self::assertFalse(RestaurantHours::isOpenAt($hours, $this->at('2026-09-16 15:00')));
        self::assertTrue(RestaurantHours::isOpenAt($hours, $this->at('2026-09-16 21:59')));
    }

    /**
     * A late kitchen types 22:00 to 02:00 and means it.
     */
    public function testARangeMayRunPastMidnight(): void
    {
        $hours = ['days' => ['wed' => [['open' => '22:00', 'close' => '02:00']]]];

        self::assertTrue(RestaurantHours::isOpenAt($hours, $this->at('2026-09-16 23:30')));
        // Thursday at 1am is still Wednesday's shift.
        self::assertTrue(RestaurantHours::isOpenAt($hours, $this->at('2026-09-17 01:00')));
        self::assertFalse(RestaurantHours::isOpenAt($hours, $this->at('2026-09-17 03:00')));
    }

    public function testAClosureBeatsTheWeeklyHours(): void
    {
        $hours = [
            'days' => ['thu' => [['open' => '11:00', 'close' => '22:00']]],
            'closures' => [['date' => '2026-11-26', 'label' => 'Thanksgiving']],
        ];

        self::assertTrue(RestaurantHours::isOpenAt($hours, $this->at('2026-11-19 12:00')));
        self::assertFalse(RestaurantHours::isOpenAt($hours, $this->at('2026-11-26 12:00')));
    }

    public function testTheFormBuildsTheStoredShape(): void
    {
        $result = RestaurantHours::fromForm([
            'open' => ['mon' => ['11:00', '17:00', '']],
            'close' => ['mon' => ['14:00', '22:00', '']],
            'closure_date' => ['2026-12-25', ''],
            'closure_label' => ['Christmas', ''],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame(
            [['open' => '11:00', 'close' => '14:00'], ['open' => '17:00', 'close' => '22:00']],
            $result['hours']['days']['mon']
        );
        self::assertSame([['date' => '2026-12-25', 'label' => 'Christmas']], $result['hours']['closures']);
        self::assertSame([], $result['hours']['days']['tue']);
    }

    public function testAHalfFilledRangeIsAnError(): void
    {
        $result = RestaurantHours::fromForm([
            'open' => ['fri' => ['11:00']],
            'close' => ['fri' => ['']],
        ]);

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('Friday', $result['errors'][0]);
    }

    public function testADayIsDescribedTheWayAMenuReadsIt(): void
    {
        self::assertSame('Closed', RestaurantHours::describeDay([]));
        self::assertSame(
            '11:00am–2:00pm, 5:00pm–10:00pm',
            RestaurantHours::describeDay([
                ['open' => '11:00', 'close' => '14:00'],
                ['open' => '17:00', 'close' => '22:00'],
            ])
        );
        self::assertSame('12:00am–12:30pm', RestaurantHours::describeDay([['open' => '00:00', 'close' => '12:30']]));
    }

    private function at(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE));
    }
}
