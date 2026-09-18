<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Services\Pricing\PricingException;
use Keel\App\Services\Pricing\PricingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The numbers on a driver's offer card, on their own.
 *
 * No database and no network: a frozen breakdown row goes in and the card's
 * figures come out, which is the whole point of the arrangement. The spec says
 * a guarantee never drops after acceptance, and the way that is kept is that
 * the card is arithmetic on the row the order was authorized against rather
 * than a second measurement of anything.
 */
class DriverOfferPricingTest extends TestCase
{
    /** The spec's seed settings, as FairPlateSeeder writes them. */
    private const SEED_SETTINGS = [
        'driver_base_cents' => 300,
        'driver_per_mile_cents' => 100,
        'driver_min_payout_cents' => 500,
        'driver_wait_free_minutes' => 10,
        'driver_wait_per_min_cents' => 20,
        'driver_wait_cap_cents' => 300,
        'processing_pct' => '0.029',
        'processing_fixed_cents' => 30,
        'platform_fee_cents' => 199,
    ];

    public function testTheCardIsTheGuaranteePlusTheTip(): void
    {
        $card = (new PricingService())->driverOffer($this->row(3.0, 200));

        self::assertSame(300, $card['base_cents']);
        self::assertSame(300, $card['mileage_cents']);
        self::assertSame(600, $card['guaranteed_cents']);
        self::assertSame(200, $card['tip_cents']);
        self::assertSame(800, $card['payout_cents']);
    }

    /**
     * A short run where base plus mileage is under the floor. The card shows the
     * floor, because that is what will be paid.
     */
    public function testTheMinimumPayoutReachesTheCard(): void
    {
        $card = (new PricingService())->driverOffer($this->row(0.5, 0));

        self::assertSame(300, $card['base_cents']);
        self::assertSame(50, $card['mileage_cents']);
        self::assertSame(500, $card['guaranteed_cents'], 'base plus mileage is 350; the floor is 500');
        self::assertSame(500, $card['payout_cents']);
    }

    /**
     * The card carries the wait-pay terms rather than a wait-pay figure: none
     * has been earned yet.
     */
    public function testTheCardCarriesTheWaitTermsFromTheSnapshot(): void
    {
        $card = (new PricingService())->driverOffer($this->row(3.0, 200));

        self::assertSame(10, $card['wait_free_minutes']);
        self::assertSame(20, $card['wait_per_min_cents']);
        self::assertSame(300, $card['wait_cap_cents']);
        self::assertArrayNotHasKey('wait_pay_cents', $card);
    }

    /**
     * The terms come off the row, so an admin changing a rate this afternoon
     * cannot restate a card that was already priced.
     */
    public function testTheCardIgnoresSettingsThatAreNotOnTheRow(): void
    {
        $row = $this->row(3.0, 200);
        $snapshot = json_decode((string) $row['settings_snapshot'], true);
        $snapshot['driver_per_mile_cents'] = 999;
        $snapshot['driver_wait_per_min_cents'] = 999;
        $row['settings_snapshot'] = (string) json_encode($snapshot);

        $card = (new PricingService())->driverOffer($row);

        self::assertSame(2997, $card['mileage_cents'], 'the row is the only authority');
        self::assertSame(999, $card['wait_per_min_cents']);
    }

    public function testARowWithNoSnapshotIsRefused(): void
    {
        $this->expectException(PricingException::class);

        (new PricingService())->driverOffer([
            'tip_cents' => 200,
            'settings_snapshot' => '{}',
        ]);
    }

    /**
     * The rate per mile, rounded to the nearest cent on the same hundredths grid
     * mileage pay uses.
     */
    #[DataProvider('perMileCases')]
    public function testThePerMileRateRoundsToTheNearestCent(int $payCents, float $miles, int $expected): void
    {
        self::assertSame($expected, (new PricingService())->payPerMile($payCents, $miles));
    }

    public static function perMileCases(): array
    {
        return [
            'exact' => [800, 4.0, 200],
            'rounds down' => [800, 3.0, 267],
            'rounds up' => [500, 0.75, 667],
            'one mile is the payout' => [625, 1.0, 625],
            // A pickup and a drop-off in the same car park. Dividing by nothing
            // has no answer, and "$8.00 a mile" would be a stranger one.
            'zero miles' => [800, 0.0, 800],
            'negative miles are not a thing' => [800, -3.0, 800],
        ];
    }

    /**
     * A frozen authorized breakdown row, priced the way PricingService would
     * have priced it.
     *
     * @return array<string, mixed>
     */
    private function row(float $miles, int $tipCents): array
    {
        $snapshot = self::SEED_SETTINGS + [
            'tax_rate' => '0.0750',
            'route_miles' => $miles,
            'is_member' => false,
        ];

        $pay = (new PricingService())->driverPay($miles, $snapshot);

        return [
            'stage' => OrderPriceBreakdown::STAGE_AUTHORIZED,
            'driver_base_cents' => $pay['driver_base'],
            'driver_mileage_cents' => $pay['driver_mileage'],
            'driver_guaranteed_cents' => $pay['driver_guaranteed'],
            'tip_cents' => $tipCents,
            'settings_snapshot' => (string) json_encode($snapshot),
        ];
    }
}
