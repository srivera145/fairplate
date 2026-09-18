<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Models\Special;
use Keel\App\Services\Pricing\Breakdown;
use Keel\App\Services\Pricing\PricingException;
use Keel\App\Services\Pricing\PricingService;
use Keel\App\Services\Routing\FakeRoutingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The money math on its own.
 *
 * Settings are handed in as a snapshot and routing is faked, so nothing here
 * touches a database or the network — which is the point: these numbers must be
 * reproducible from the spec alone. The seed values below are the spec's, and
 * where a test needs a different rate it says so in the test.
 */
class PricingServiceTest extends TestCase
{
    /** The spec's seed settings, exactly as FairPlateSeeder writes them. */
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

    /** Leon County, FL: 6% state plus 1.5% discretionary surtax. */
    private const TAX_RATE = '0.0750';

    private const RESTAURANT_LAT = 30.4383;
    private const RESTAURANT_LNG = -84.2807;
    private const ADDRESS_LAT = 30.4500;
    private const ADDRESS_LNG = -84.3000;

    // -----------------------------------------------------------------
    // Driver pay
    // -----------------------------------------------------------------

    /**
     * The spec's four worked cases, including the two the minimum payout rescues.
     */
    #[DataProvider('driverPayCases')]
    public function testDriverGuaranteedMatchesTheSpec(float $miles, int $expected): void
    {
        self::assertSame($expected, $this->service()->driverGuaranteed($miles, $this->snapshot()));
    }

    public static function driverPayCases(): array
    {
        return [
            '3.0 mi' => [3.0, 600],
            '1.0 mi, minimum applies' => [1.0, 500],
            '7.5 mi' => [7.5, 1050],
            '0.4 mi, minimum applies' => [0.4, 500],
        ];
    }

    public function testDriverPayReportsBaseAndMileageSeparately(): void
    {
        $parts = $this->service()->driverPay(7.5, $this->snapshot());

        self::assertSame(300, $parts['driver_base']);
        self::assertSame(750, $parts['driver_mileage']);
        self::assertSame(1050, $parts['driver_guaranteed']);
    }

    public function testBaseAndMileageNeedNotSumToTheGuarantee(): void
    {
        // The whole reason they are detail and not charge lines.
        $parts = $this->service()->driverPay(0.4, $this->snapshot());

        self::assertSame(340, $parts['driver_base'] + $parts['driver_mileage']);
        self::assertSame(500, $parts['driver_guaranteed']);
    }

    public function testMileageRoundsToTheNearestCent(): void
    {
        // 2.345 mi snaps to the 2.35 the route is stored at: 300 + 235.
        self::assertSame(535, $this->service()->driverGuaranteed(2.345, $this->snapshot()));
    }

    // -----------------------------------------------------------------
    // Wait pay
    // -----------------------------------------------------------------

    /**
     * The spec's five worked cases: free window, per-minute, and the cap.
     */
    #[DataProvider('waitPayCases')]
    public function testWaitPayMatchesTheSpec(int $minutes, int $expected): void
    {
        self::assertSame($expected, $this->service()->waitPay($minutes, $this->snapshot()));
    }

    public static function waitPayCases(): array
    {
        return [
            '9 min, inside the free window' => [9, 0],
            '10 min, the free window exactly' => [10, 0],
            '14 min' => [14, 80],
            '25 min, the cap exactly' => [25, 300],
            '40 min, capped' => [40, 300],
        ];
    }

    public function testWaitPayNeverGoesNegative(): void
    {
        self::assertSame(0, $this->service()->waitPay(0, $this->snapshot()));
        self::assertSame(0, $this->service()->waitPay(-5, $this->snapshot()));
    }

    // -----------------------------------------------------------------
    // The processing gross-up
    // -----------------------------------------------------------------

    /**
     * The service fee covers the processor and overshoots by at most a cent.
     *
     * Randomized because the property has to hold everywhere, not at the four
     * amounts someone thought to write down. The seed is fixed so a failure is
     * reproducible.
     *
     * The comparison runs in integers scaled by the rate's denominator: as a
     * float, 0.029 × a total is not exactly 2.9% of it, and a check that cannot
     * state the rule exactly cannot enforce it.
     */
    public function testGrossUpCoversProcessingWithinOneCent(): void
    {
        mt_srand(20260918);

        $service = $this->service();
        $snapshot = $this->snapshot();

        self::assertSame('0.029', $snapshot['processing_pct']);
        self::assertSame(30, $snapshot['processing_fixed_cents']);

        // 0.029 = 29/1000.
        $numerator = 29;
        $denominator = 1000;
        $fixed = 30;

        for ($i = 0; $i < 50; $i++) {
            $subtotal = mt_rand(500, 20000);

            $quote = $service->quote(
                ['items' => [$this->item($subtotal)], 'tip_cents' => 0],
                $this->restaurant(['tax_rate' => '0.0000']),
                $this->address(),
                ['is_member' => true],
                $this->noon()
            );

            $breakdown = $quote['estimate'];
            $breakdown->assertBalanced();

            $total = $breakdown->total();
            $serviceFee = $breakdown->line(Breakdown::SERVICE_FEE);
            $chargeable = $total - $serviceFee;

            $required = ($numerator * $total) + ($fixed * $denominator);
            $charged = $serviceFee * $denominator;

            self::assertGreaterThanOrEqual(
                $required,
                $charged,
                "S={$chargeable}: the service fee must cover processing."
            );
            self::assertLessThanOrEqual(
                $denominator,
                $charged - $required,
                "S={$chargeable}: the service fee must not overshoot by more than a cent."
            );
        }
    }

    public function testGrossUpIsExactOnAWorkedExample(): void
    {
        // A member, no tax, no tip, one mile: 500 of food plus the 500 minimum
        // payout makes S exactly 1000.
        $breakdown = $this->quoteFor(500, 0, ['tax_rate' => '0.0000'], ['is_member' => true], 1.0)['estimate'];

        $chargeable = $breakdown->total() - $breakdown->line(Breakdown::SERVICE_FEE);

        self::assertSame(1000, $chargeable);
        // (1000 + 30) / 0.971 is 1060.76..., which ceilings to 1061.
        self::assertSame(1061, $breakdown->total());
        self::assertSame(61, $breakdown->line(Breakdown::SERVICE_FEE));
    }

    // -----------------------------------------------------------------
    // Tax
    // -----------------------------------------------------------------

    public function testTaxIsTheTaxableSubtotalRoundedToTheNearestCent(): void
    {
        // 1234 × 0.0750 = 92.55, which rounds to 93.
        $quote = $this->quoteFor(1234, 0);

        self::assertSame(93, $quote['estimate']->line(Breakdown::TAX));
    }

    public function testATaxExemptLineIsLeftOutOfTheTaxableSubtotal(): void
    {
        $quote = $this->service()->quote(
            [
                'items' => [
                    $this->item(1000),
                    ['menu_item_id' => 2, 'quantity' => 1, 'price_cents' => 500, 'taxable' => false],
                ],
                'tip_cents' => 0,
            ],
            $this->restaurant(),
            $this->address(),
            ['is_member' => false],
            $this->noon()
        );

        self::assertSame(1500, $quote['estimate']->line(Breakdown::SUBTOTAL));
        // Only the taxable 1000 is taxed: 1000 × 0.0750 = 75.
        self::assertSame(75, $quote['estimate']->line(Breakdown::TAX));
    }

    // -----------------------------------------------------------------
    // Membership
    // -----------------------------------------------------------------

    public function testAMemberPaysNoPlatformFee(): void
    {
        $quote = $this->quoteFor(2000, 500, [], ['is_member' => true]);

        self::assertSame(0, $quote['estimate']->line(Breakdown::PLATFORM_FEE));
        self::assertTrue($quote['is_member']);
        self::assertTrue($quote['settings_snapshot']['is_member']);
    }

    public function testANonMemberPaysThePlatformFee(): void
    {
        $quote = $this->quoteFor(2000, 500, [], ['is_member' => false]);

        self::assertSame(199, $quote['estimate']->line(Breakdown::PLATFORM_FEE));
        self::assertFalse($quote['is_member']);
        self::assertFalse($quote['settings_snapshot']['is_member']);
    }

    public function testMembershipIsSnapshottedSoALapsedMemberStillSettlesFree(): void
    {
        $quote = $this->quoteFor(2000, 500, [], ['is_member' => true]);

        $final = $this->service()->finalize($this->orderFrom($quote), 30);

        self::assertSame(0, $final->line(Breakdown::PLATFORM_FEE));
    }

    // -----------------------------------------------------------------
    // Specials
    // -----------------------------------------------------------------

    public function testASpecialAppliesInsideItsDayAndTimeWindow(): void
    {
        $quote = $this->quoteWithSpecial($this->tuesdayLunchPercentSpecial(), $this->tuesdayAt('12:00:00'));

        // 2000 less 20% is 1600.
        self::assertSame(1600, $quote['estimate']->line(Breakdown::SUBTOTAL));
        self::assertSame(400, $quote['subtotal']['discount_cents']);
    }

    public function testTheSameSpecialDoesNotApplyOutsideItsTimeWindow(): void
    {
        $quote = $this->quoteWithSpecial($this->tuesdayLunchPercentSpecial(), $this->tuesdayAt('15:30:00'));

        self::assertSame(2000, $quote['estimate']->line(Breakdown::SUBTOTAL));
        self::assertSame(0, $quote['subtotal']['discount_cents']);
    }

    public function testTheSameSpecialDoesNotApplyOnAnotherDay(): void
    {
        // Same clock time, one day later.
        $quote = $this->quoteWithSpecial($this->tuesdayLunchPercentSpecial(), $this->wednesdayAt('12:00:00'));

        self::assertSame(2000, $quote['estimate']->line(Breakdown::SUBTOTAL));
    }

    public function testAnInactiveSpecialNeverApplies(): void
    {
        $special = $this->tuesdayLunchPercentSpecial();
        $special['active'] = 0;

        $quote = $this->quoteWithSpecial($special, $this->tuesdayAt('12:00:00'));

        self::assertSame(2000, $quote['estimate']->line(Breakdown::SUBTOTAL));
    }

    public function testSpecialsAreEvaluatedInEasternTimeNotUtc(): void
    {
        // 16:00 UTC is 12:00 in New York, inside the lunch window; the same
        // instant read as UTC would fall outside it.
        $utcNoonEastern = new \DateTimeImmutable('2026-09-15 16:00:00', new \DateTimeZone('UTC'));

        $quote = $this->quoteWithSpecial($this->tuesdayLunchPercentSpecial(), $utcNoonEastern);

        self::assertSame(1600, $quote['estimate']->line(Breakdown::SUBTOTAL));
    }

    public function testAnAmountSpecialComesOffEachUnit(): void
    {
        $special = [
            'id' => 7,
            'active' => 1,
            'type' => Special::TYPE_AMOUNT,
            'value_cents' => 150,
            'menu_item_id' => 1,
            'days' => null,
            'start_time' => null,
            'end_time' => null,
        ];

        $quote = $this->service()->quote(
            ['items' => [['menu_item_id' => 1, 'quantity' => 2, 'price_cents' => 1000]], 'tip_cents' => 0],
            $this->restaurant(['specials' => [$special]]),
            $this->address(),
            ['is_member' => false],
            $this->noon()
        );

        self::assertSame(1700, $quote['estimate']->line(Breakdown::SUBTOTAL));
    }

    public function testAPriceSpecialSetsTheItemPriceAndOptionsStillAddOnTop(): void
    {
        $special = [
            'id' => 8,
            'active' => 1,
            'type' => Special::TYPE_PRICE,
            'value_cents' => 500,
            'menu_item_id' => 1,
            'days' => null,
            'start_time' => null,
            'end_time' => null,
        ];

        $quote = $this->service()->quote(
            [
                'items' => [[
                    'menu_item_id' => 1,
                    'quantity' => 1,
                    'price_cents' => 900,
                    'options' => [['price_delta_cents' => 125]],
                ]],
                'tip_cents' => 0,
            ],
            $this->restaurant(['specials' => [$special]]),
            $this->address(),
            ['is_member' => false],
            $this->noon()
        );

        self::assertSame(625, $quote['estimate']->line(Breakdown::SUBTOTAL));
    }

    public function testASpecialNeverRaisesAPriceOrDrivesALineBelowZero(): void
    {
        $tooGenerous = [
            'id' => 9,
            'active' => 1,
            'type' => Special::TYPE_AMOUNT,
            'value_cents' => 99999,
            'menu_item_id' => 1,
            'days' => null,
            'start_time' => null,
            'end_time' => null,
        ];
        $wouldRaise = [
            'id' => 10,
            'active' => 1,
            'type' => Special::TYPE_PRICE,
            'value_cents' => 5000,
            'menu_item_id' => 2,
            'days' => null,
            'start_time' => null,
            'end_time' => null,
        ];

        $quote = $this->service()->quote(
            [
                'items' => [
                    ['menu_item_id' => 1, 'quantity' => 1, 'price_cents' => 1000],
                    ['menu_item_id' => 2, 'quantity' => 1, 'price_cents' => 1000],
                ],
                'tip_cents' => 0,
            ],
            $this->restaurant(['specials' => [$tooGenerous, $wouldRaise]]),
            $this->address(),
            ['is_member' => false],
            $this->noon()
        );

        // The first item is free but not negative; the second keeps its price.
        self::assertSame(1000, $quote['estimate']->line(Breakdown::SUBTOTAL));
    }

    public function testOnlyTheBestSpecialAppliesToAnItem(): void
    {
        $tenPercent = [
            'id' => 11, 'active' => 1, 'type' => Special::TYPE_PERCENT, 'value_pct' => '0.1000',
            'menu_item_id' => 1, 'days' => null, 'start_time' => null, 'end_time' => null,
        ];
        $threeDollars = [
            'id' => 12, 'active' => 1, 'type' => Special::TYPE_AMOUNT, 'value_cents' => 300,
            'menu_item_id' => 1, 'days' => null, 'start_time' => null, 'end_time' => null,
        ];

        $quote = $this->service()->quote(
            ['items' => [['menu_item_id' => 1, 'quantity' => 1, 'price_cents' => 1000]], 'tip_cents' => 0],
            $this->restaurant(['specials' => [$tenPercent, $threeDollars]]),
            $this->address(),
            ['is_member' => false],
            $this->noon()
        );

        // 300 off beats 100 off, and they do not stack.
        self::assertSame(700, $quote['estimate']->line(Breakdown::SUBTOTAL));
    }

    public function testAnOrderWideSpecialSplitsAcrossLinesWithoutLosingACent(): void
    {
        $orderWide = [
            'id' => 13, 'active' => 1, 'type' => Special::TYPE_PERCENT, 'value_pct' => '0.1000',
            'menu_item_id' => null, 'days' => null, 'start_time' => null, 'end_time' => null,
        ];

        $quote = $this->service()->quote(
            [
                'items' => [
                    ['menu_item_id' => 1, 'quantity' => 1, 'price_cents' => 333],
                    ['menu_item_id' => 2, 'quantity' => 1, 'price_cents' => 333],
                    ['menu_item_id' => 3, 'quantity' => 1, 'price_cents' => 333],
                ],
                'tip_cents' => 0,
            ],
            $this->restaurant(['specials' => [$orderWide]]),
            $this->address(),
            ['is_member' => false],
            $this->noon()
        );

        // 999 less 10% (99.9 → 100) is 899, and the lines still sum to it.
        $lineSum = array_sum(array_column($quote['subtotal']['items'], 'line_cents'));

        self::assertSame(899, $quote['estimate']->line(Breakdown::SUBTOTAL));
        self::assertSame(899, $lineSum);
    }

    // -----------------------------------------------------------------
    // Estimate against authorization
    // -----------------------------------------------------------------

    /**
     * The authorization is the worst the order can become, so it is never less.
     */
    #[DataProvider('quoteShapes')]
    public function testTheAuthorizationIsNeverBelowTheEstimate(int $subtotal, int $tip, bool $isMember, float $miles): void
    {
        $quote = $this->quoteFor($subtotal, $tip, [], ['is_member' => $isMember], $miles);

        $estimate = $quote['estimate'];
        $authorization = $quote['authorization'];

        $estimate->assertBalanced();
        $authorization->assertBalanced();

        self::assertGreaterThanOrEqual($estimate->total(), $authorization->total());
        self::assertSame(0, $estimate->line(Breakdown::WAIT_PAY));
        self::assertSame(300, $authorization->line(Breakdown::WAIT_PAY));
    }

    public static function quoteShapes(): array
    {
        return [
            'small non-member order' => [500, 0, false, 0.4],
            'typical non-member order' => [2350, 500, false, 3.0],
            'member order, long run' => [8900, 1500, true, 7.5],
            'large member order, no tip' => [20000, 0, true, 12.25],
            'zero tip, minimum payout' => [1200, 0, false, 1.0],
        ];
    }

    public function testTheEstimateCarriesNoWaitPayAndTheAuthorizationCarriesTheCap(): void
    {
        $quote = $this->quoteFor(2000, 300);

        self::assertSame(OrderPriceBreakdown::STAGE_ESTIMATE, $quote['estimate']->stage());
        self::assertSame(OrderPriceBreakdown::STAGE_AUTHORIZED, $quote['authorization']->stage());
        self::assertSame(0, $quote['estimate']->metaValue('wait_minutes'));
        self::assertSame(25, $quote['authorization']->metaValue('wait_minutes'));
    }

    public function testTheQuoteCarriesRouteMilesAndASettingsSnapshot(): void
    {
        $quote = $this->quoteFor(2000, 300, [], ['is_member' => false], 4.25);

        self::assertSame(4.25, $quote['route_miles']);
        self::assertSame(4.25, $quote['estimate']->routeMiles());

        foreach (PricingService::SETTING_KEYS as $key) {
            self::assertArrayHasKey($key, $quote['settings_snapshot'], $key . ' must be snapshotted');
        }

        self::assertSame(self::TAX_RATE, $quote['settings_snapshot']['tax_rate']);
        self::assertSame(4.25, $quote['settings_snapshot']['route_miles']);
    }

    public function testTheQuoteRoutesFromTheRestaurantToTheCustomer(): void
    {
        $routing = new FakeRoutingService(3.0);

        (new PricingService($routing, self::SEED_SETTINGS))->quote(
            $this->cart(),
            $this->restaurant(),
            $this->address(),
            ['is_member' => false],
            $this->noon()
        );

        self::assertSame(1, $routing->callCount());
        self::assertSame(
            [
                'from_lat' => self::RESTAURANT_LAT,
                'from_lng' => self::RESTAURANT_LNG,
                'to_lat' => self::ADDRESS_LAT,
                'to_lng' => self::ADDRESS_LNG,
            ],
            $routing->calls()[0]
        );
    }

    /**
     * The full charge line-up on one ordinary order, computed by hand.
     */
    public function testAWorkedOrderPricesExactlyAsTheSpecSaysItShould(): void
    {
        $quote = $this->quoteFor(2350, 500, [], ['is_member' => false], 3.0);

        $estimate = $quote['estimate'];

        //   subtotal      2350
        //   tax           176   (2350 × 0.0750 = 176.25 → 176)
        //   driver pay    600   (300 + 100 × 3.0)
        //   wait pay        0
        //   tip           500
        //   platform fee  199
        //   S            3825
        //   total        ceil(3855 / 0.971) = 3971
        //   service fee   146
        self::assertSame(
            [
                Breakdown::SUBTOTAL => 2350,
                Breakdown::TAX => 176,
                Breakdown::DRIVER_PAY => 600,
                Breakdown::WAIT_PAY => 0,
                Breakdown::TIP => 500,
                Breakdown::PLATFORM_FEE => 199,
                Breakdown::SERVICE_FEE => 146,
            ],
            $estimate->lines()
        );
        self::assertSame(3971, $estimate->total());
        $estimate->assertBalanced();
    }

    // -----------------------------------------------------------------
    // Settling from the snapshot
    // -----------------------------------------------------------------

    public function testFinalizeRecomputesOnlyTheWaitPay(): void
    {
        $quote = $this->quoteFor(2350, 500, [], ['is_member' => false], 3.0);
        $order = $this->orderFrom($quote);

        $final = $this->service()->finalize($order, 14);

        self::assertSame(OrderPriceBreakdown::STAGE_FINAL, $final->stage());
        self::assertSame(80, $final->line(Breakdown::WAIT_PAY));

        foreach ([Breakdown::SUBTOTAL, Breakdown::TAX, Breakdown::DRIVER_PAY, Breakdown::TIP, Breakdown::PLATFORM_FEE] as $line) {
            self::assertSame(
                $quote['authorization']->line($line),
                $final->line($line),
                $line . ' must not move between authorization and capture'
            );
        }

        $final->assertBalanced();
    }

    public function testTheCaptureNeverExceedsTheAuthorization(): void
    {
        $quote = $this->quoteFor(2350, 500, [], ['is_member' => false], 3.0);
        $order = $this->orderFrom($quote);

        foreach ([0, 9, 10, 14, 25, 40, 120] as $minutes) {
            $final = $this->service()->finalize($order, $minutes);
            $final->assertBalanced();

            self::assertLessThanOrEqual(
                $quote['authorization']->total(),
                $final->total(),
                "a {$minutes} minute wait must still settle inside the authorization"
            );
        }
    }

    /**
     * The rule the whole snapshot exists for.
     */
    public function testChangingSettingsAfterAQuoteDoesNotChangeFinalize(): void
    {
        $quote = $this->quoteFor(2350, 500, [], ['is_member' => false], 3.0);
        $order = $this->orderFrom($quote);

        $expected = $this->service()->finalize($order, 14);

        // An admin doubles the driver base, triples the platform fee, raises
        // the processing rate and stretches the wait window.
        $changed = [
            'driver_base_cents' => 600,
            'driver_per_mile_cents' => 250,
            'driver_min_payout_cents' => 900,
            'driver_wait_free_minutes' => 2,
            'driver_wait_per_min_cents' => 75,
            'driver_wait_cap_cents' => 1500,
            'processing_pct' => '0.055',
            'processing_fixed_cents' => 60,
            'platform_fee_cents' => 599,
        ];

        $afterTheChange = (new PricingService(new FakeRoutingService(3.0), $changed))->finalize($order, 14);

        self::assertSame($expected->lines(), $afterTheChange->lines());
        self::assertSame($expected->total(), $afterTheChange->total());
        $afterTheChange->assertBalanced();
    }

    public function testFinalizeRefusesAnOrderWithNoFrozenBreakdown(): void
    {
        $this->expectException(PricingException::class);

        $this->service()->finalize(['id' => 0], 12);
    }

    public function testFinalizeRefusesToExceedARecordedAuthorization(): void
    {
        $quote = $this->quoteFor(2350, 500, [], ['is_member' => false], 3.0);

        $order = $this->orderFrom($quote);
        // An authorization a cent short of what the cap can reach.
        $order['authorized_cents'] = $quote['authorization']->total() - 1;

        $this->expectException(PricingException::class);

        $this->service()->finalize($order, 40);
    }

    // -----------------------------------------------------------------
    // Tip adjustments
    // -----------------------------------------------------------------

    public function testATipAdjustmentIsGrossedUpOnItsOwn(): void
    {
        $quote = $this->quoteFor(2350, 500, [], ['is_member' => false], 3.0);
        $order = $this->orderFrom($quote);

        $adjustment = $this->service()->tipAdjustment($order, 500);

        // ceil((500 + 30) / 0.971) = 546, so the fee is 46.
        self::assertSame(546, $adjustment->total());
        self::assertSame(500, $adjustment->line(Breakdown::TIP));
        self::assertSame(46, $adjustment->line(Breakdown::SERVICE_FEE));
        $adjustment->assertBalanced();
    }

    public function testATipAdjustmentUsesTheSnapshotNotLiveSettings(): void
    {
        $quote = $this->quoteFor(2350, 500, [], ['is_member' => false], 3.0);
        $order = $this->orderFrom($quote);

        $live = new PricingService(null, ['processing_pct' => '0.5', 'processing_fixed_cents' => 500] + self::SEED_SETTINGS);

        self::assertSame(546, $live->tipAdjustment($order, 500)->total());
    }

    public function testATipAdjustmentNeedsAPositiveDelta(): void
    {
        $quote = $this->quoteFor(2350, 500, [], ['is_member' => false], 3.0);

        $this->expectException(PricingException::class);

        $this->service()->tipAdjustment($this->orderFrom($quote), 0);
    }

    // -----------------------------------------------------------------
    // Balance, everywhere
    // -----------------------------------------------------------------

    /**
     * Every breakdown this suite produces balances to the cent.
     */
    public function testEveryBreakdownProducedBalances(): void
    {
        mt_srand(4713);

        $asserted = 0;

        for ($i = 0; $i < 40; $i++) {
            $quote = $this->quoteFor(
                mt_rand(300, 30000),
                mt_rand(0, 3000),
                ['tax_rate' => ['0.0000', '0.0750', '0.0825', '0.1125'][mt_rand(0, 3)]],
                ['is_member' => (bool) mt_rand(0, 1)],
                mt_rand(0, 2500) / 100
            );

            foreach ([$quote['estimate'], $quote['authorization']] as $breakdown) {
                $breakdown->assertBalanced();
                self::assertTrue($breakdown->isBalanced());
                $asserted++;
            }

            $order = $this->orderFrom($quote);

            $final = $this->service()->finalize($order, mt_rand(0, 60));
            $final->assertBalanced();
            self::assertTrue($final->isBalanced());
            $asserted++;

            $adjustment = $this->service()->tipAdjustment($order, mt_rand(1, 2000));
            $adjustment->assertBalanced();
            self::assertTrue($adjustment->isBalanced());
            $asserted++;
        }

        self::assertSame(160, $asserted);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function service(float $miles = 3.0): PricingService
    {
        return new PricingService(new FakeRoutingService($miles), self::SEED_SETTINGS);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return self::SEED_SETTINGS + ['tax_rate' => self::TAX_RATE];
    }

    /**
     * @return array{
     *     route_miles: float, is_member: bool, settings_snapshot: array<string, mixed>,
     *     subtotal: array<string, mixed>, estimate: Breakdown, authorization: Breakdown
     * }
     */
    private function quoteFor(
        int $subtotalCents,
        int $tipCents,
        array $restaurantOverrides = [],
        array $customer = ['is_member' => false],
        float $miles = 3.0
    ): array {
        return $this->service($miles)->quote(
            ['items' => [$this->item($subtotalCents)], 'tip_cents' => $tipCents],
            $this->restaurant($restaurantOverrides),
            $this->address(),
            $customer,
            $this->noon()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteWithSpecial(array $special, \DateTimeImmutable $at): array
    {
        return $this->service()->quote(
            ['items' => [$this->item(2000)], 'tip_cents' => 0],
            $this->restaurant(['specials' => [$special]]),
            $this->address(),
            ['is_member' => false],
            $at
        );
    }

    /**
     * A quoted order as it would be stored: the authorized row, frozen.
     *
     * @return array<string, mixed>
     */
    private function orderFrom(array $quote): array
    {
        return [
            'id' => 1,
            'route_miles' => $quote['route_miles'],
            'authorized_cents' => $quote['authorization']->total(),
            'breakdown' => $quote['authorization']->toRow(1),
        ];
    }

    private function cart(array $overrides = []): array
    {
        return $overrides + ['items' => [$this->item(2000)], 'tip_cents' => 0];
    }

    private function item(int $priceCents, int $menuItemId = 1): array
    {
        return ['menu_item_id' => $menuItemId, 'quantity' => 1, 'price_cents' => $priceCents];
    }

    private function restaurant(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1,
            'lat' => self::RESTAURANT_LAT,
            'lng' => self::RESTAURANT_LNG,
            'tax_rate' => self::TAX_RATE,
            'specials' => [],
        ];
    }

    private function address(): array
    {
        return ['id' => 1, 'lat' => self::ADDRESS_LAT, 'lng' => self::ADDRESS_LNG];
    }

    /**
     * 20% off item 1, Tuesdays from 11:00 to 14:00.
     *
     * @return array<string, mixed>
     */
    private function tuesdayLunchPercentSpecial(): array
    {
        return [
            'id' => 1,
            'restaurant_id' => 1,
            'active' => 1,
            'type' => Special::TYPE_PERCENT,
            'value_pct' => '0.2000',
            'value_cents' => null,
            'menu_item_id' => 1,
            'days' => json_encode([2]),
            'start_time' => '11:00:00',
            'end_time' => '14:00:00',
        ];
    }

    private function noon(): \DateTimeImmutable
    {
        return $this->tuesdayAt('12:00:00');
    }

    private function tuesdayAt(string $time): \DateTimeImmutable
    {
        // 2026-09-15 is a Tuesday.
        return new \DateTimeImmutable('2026-09-15 ' . $time, new \DateTimeZone(PricingService::TIMEZONE));
    }

    private function wednesdayAt(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-16 ' . $time, new \DateTimeZone(PricingService::TIMEZONE));
    }
}
