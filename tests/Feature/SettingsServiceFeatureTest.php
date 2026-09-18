<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Services\MissingSettingException;
use Keel\App\Services\Settings;
use Keel\Core\Database;
use Keel\Database\Seeders\FairPlateSeeder;
use Tests\TestCase;

class SettingsServiceFeatureTest extends TestCase
{
    /** Every seeded key with the type it must come back as. */
    private const EXPECTED = [
        'driver_base_cents' => ['int', 300],
        'driver_per_mile_cents' => ['int', 100],
        'driver_min_payout_cents' => ['int', 500],
        'driver_wait_free_minutes' => ['int', 10],
        'driver_wait_per_min_cents' => ['int', 20],
        'driver_wait_cap_cents' => ['int', 300],
        'processing_pct' => ['decimal', '0.029'],
        'processing_fixed_cents' => ['int', 30],
        'platform_fee_cents' => ['int', 199],
        'membership_price_cents' => ['int', 999],
        'comparison_commission_pct' => ['decimal', '0.25'],
        'offer_timeout_seconds' => ['int', 45],
        'dispatch_max_rounds' => ['int', 5],
        'dispatch_max_minutes' => ['int', 8],
        'tip_adjust_window_hours' => ['int', 24],
        'location_ping_online_seconds' => ['int', 15],
        'location_ping_active_seconds' => ['int', 10],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        (new FairPlateSeeder())->run();
    }

    public function testEverySeededKeyReturnsTheRightTypeAndValue(): void
    {
        foreach (self::EXPECTED as $key => [$type, $expected]) {
            self::assertSame($type, Settings::typeOf($key), "{$key} should be declared {$type}");

            if ($type === 'int') {
                $actual = Settings::int($key);
                self::assertIsInt($actual, "{$key} should read back as int");
            } else {
                $actual = Settings::decimal($key);
                self::assertIsString($actual, "{$key} should read back as an exact decimal string");
            }

            self::assertSame($expected, $actual, "{$key} should be {$expected}");
            self::assertSame($expected, Settings::get($key), "{$key} via get() should match");
        }

        self::assertCount(17, self::EXPECTED);
    }

    public function testMissingKeyThrows(): void
    {
        $this->expectException(MissingSettingException::class);
        $this->expectExceptionMessage('no_such_setting');

        Settings::get('no_such_setting');
    }

    public function testReadingAKeyAsTheWrongTypeThrows(): void
    {
        $this->expectException(MissingSettingException::class);

        Settings::decimal('platform_fee_cents');
    }

    public function testDecimalsAreExactStringsNotFloats(): void
    {
        // 0.029 has no exact float representation; the string must survive intact.
        self::assertSame('0.029', Settings::decimal('processing_pct'));
        self::assertNotSame(0.029, Settings::get('processing_pct'));
    }

    public function testTheCacheIsRequestLifetimeAndSurvivesRepeatedReads(): void
    {
        // Warm the cache, then learn what one measurement costs, since asking
        // the server for its statement counter is itself a statement.
        Settings::int('platform_fee_cents');

        $first = $this->questionCount();
        $overhead = $this->questionCount() - $first;

        $before = $this->questionCount();

        for ($i = 0; $i < 20; $i++) {
            Settings::int('platform_fee_cents');
            Settings::decimal('processing_pct');
        }

        // Forty reads add nothing beyond the measurement itself.
        self::assertSame($overhead, $this->questionCount() - $before);
    }

    public function testTheCacheIsRebuiltAfterAFlush(): void
    {
        Settings::int('platform_fee_cents');

        $before = $this->questionCount();
        Settings::flush();
        Settings::int('platform_fee_cents');

        // The flush forces exactly one reload on the next read.
        self::assertGreaterThan($before + 1, $this->questionCount());
    }

    public function testWritingASettingInvalidatesTheCache(): void
    {
        self::assertSame(199, Settings::int('platform_fee_cents'));

        Settings::put('platform_fee_cents', '249', 'int');

        self::assertSame(249, Settings::int('platform_fee_cents'));
    }

    /**
     * The server's own counter of statements run on this connection, which is
     * how we tell whether the cache actually spared the database.
     */
    private function questionCount(): int
    {
        $row = Database::connection()
            ->query("SHOW SESSION STATUS LIKE 'Questions'")
            ->fetch();

        return (int) ($row['Value'] ?? 0);
    }
}
