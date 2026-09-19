<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Services\Billing\TierBillingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tier arithmetic on its own.
 *
 * The scale is handed in as rows and the comparison rate as a string, so
 * nothing here touches a database — which is the point: these numbers must be
 * reproducible from the spec alone. The rows below are exactly what
 * FairPlateSeeder writes, and where a test needs a different scale it builds
 * one and says why.
 *
 * The boundaries are the whole reason this file exists. Every one of them is a
 * place a restaurant's bill changes by hundreds of dollars on a single order,
 * and an off-by-one at 100 or 250 is not a rounding error, it is an invoice
 * somebody has to be argued out of.
 */
class TierBillingServiceTest extends TestCase
{
    /** The spec's seed scale: min, max, fee, is_custom. */
    private const SEED_TIERS = [
        [0, 40, 0, 0],
        [41, 100, 24900, 0],
        [101, 250, 54900, 0],
        [251, 500, 99900, 0],
        [501, null, 0, 1],
    ];

    private const COMPARISON_PCT = '0.25';

    // -----------------------------------------------------------------
    // The boundaries
    // -----------------------------------------------------------------

    /**
     * Every edge of the seeded scale, from the spec's own table.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function boundaries(): array
    {
        return [
            'nothing at all' => [0, 0],
            'one under the first fee' => [40, 0],
            'the first order that costs anything' => [41, 24900],
            'the top of the first paid tier' => [100, 24900],
            'one over it' => [101, 54900],
            'the top of the second' => [250, 54900],
            'one over that' => [251, 99900],
            'the top of the third' => [500, 99900],
        ];
    }

    #[DataProvider('boundaries')]
    public function testTheFeeAtEveryTierBoundary(int $orders, int $expectedCents): void
    {
        self::assertSame($expectedCents, $this->service()->feeFor($this->restaurant(), $orders));
    }

    /**
     * Past the last fixed tier there is no fee to quote, only a conversation.
     *
     * Null rather than zero, and the difference is the whole point: a
     * restaurant doing 501 orders a month owes something, nobody has decided
     * what, and an invoice for $0.00 would say it owes nothing.
     */
    public function testTheCustomTierHasNoFeeUntilAnAdminSetsOne(): void
    {
        $service = $this->service();

        self::assertNull($service->feeFor($this->restaurant(), 501));
        self::assertSame(1, (int) $service->tierFor(501)['is_custom']);
    }

    public function testTheCustomTierUsesTheFigureAnAdminSet(): void
    {
        $restaurant = $this->restaurant(['custom_fee_cents' => 149900]);

        self::assertSame(149900, $this->service()->feeFor($restaurant, 501));
        self::assertSame(149900, $this->service()->feeFor($restaurant, 5000));
    }

    /**
     * The custom fee is only the custom tier's. A restaurant that negotiated
     * one and then had a quiet month pays the ordinary tier for that month.
     */
    public function testACustomFeeDoesNotLeakIntoTheFixedTiers(): void
    {
        $restaurant = $this->restaurant(['custom_fee_cents' => 149900]);

        self::assertSame(24900, $this->service()->feeFor($restaurant, 41));
        self::assertSame(0, $this->service()->feeFor($restaurant, 40));
    }

    // -----------------------------------------------------------------
    // The founding discount
    // -----------------------------------------------------------------

    /**
     * The spec's worked example: 20% off the 41–100 tier is 19920.
     */
    public function testAFoundingDiscountComesOffTheTierFee(): void
    {
        $founder = $this->restaurant(['founding_discount_pct' => '0.2000']);

        self::assertSame(19920, $this->service()->feeFor($founder, 41));
        self::assertSame(4980, $this->service()->discountFor($founder, 41));
        self::assertSame(24900, $this->service()->listFeeFor($founder, 41));
    }

    public function testTheDiscountAppliesOnEveryPaidTier(): void
    {
        $founder = $this->restaurant(['founding_discount_pct' => '0.2000']);
        $service = $this->service();

        self::assertSame(0, $service->feeFor($founder, 40), 'nothing off nothing');
        self::assertSame(43920, $service->feeFor($founder, 101));
        self::assertSame(79920, $service->feeFor($founder, 251));
    }

    public function testTheDiscountAppliesToACustomFeeToo(): void
    {
        $founder = $this->restaurant([
            'founding_discount_pct' => '0.2000',
            'custom_fee_cents' => 149900,
        ]);

        self::assertSame(119920, $this->service()->feeFor($founder, 501));
    }

    /**
     * Rounded to the cent, halves away from zero — the same rounding tax and
     * every other percentage in FairPlate uses. A third off 24900 is 8300
     * exactly; a rate that does not divide evenly still lands on a whole cent.
     */
    public function testTheDiscountRoundsToTheCent(): void
    {
        // 24900 × 0.0755 = 1879.95, which rounds to 1880.
        $founder = $this->restaurant(['founding_discount_pct' => '0.0755']);

        self::assertSame(1880, $this->service()->discountFor($founder, 41));
        self::assertSame(23020, $this->service()->feeFor($founder, 41));
    }

    public function testNoDiscountColumnIsTheSameAsNoDiscount(): void
    {
        $plain = $this->restaurant();
        unset($plain['founding_discount_pct']);

        self::assertSame(24900, $this->service()->feeFor($plain, 41));
    }

    // -----------------------------------------------------------------
    // The comparison
    // -----------------------------------------------------------------

    public function testTheCommissionEquivalentIsAQuarterOfTheFoodSold(): void
    {
        self::assertSame(250000, $this->service()->commissionEquivalent(1000000));
        self::assertSame(0, $this->service()->commissionEquivalent(0));
    }

    /**
     * Rounded once, on the month's total, rather than per order. The spec says
     * "sum of subtotals × comparison_commission_pct", and that is one
     * multiplication.
     */
    public function testTheCommissionEquivalentRoundsOnceAtTheEnd(): void
    {
        // 4 × 1013 = 4052; 4052 × 0.25 = 1013 exactly.
        // Rounding per order would give 4 × round(253.25) = 4 × 253 = 1012.
        self::assertSame(1013, $this->service()->commissionEquivalent(4052));
    }

    public function testSavingsAreTheCommissionNotChargedLessTheFeeThatWas(): void
    {
        $service = $this->service();

        self::assertSame(225100, $service->savings(250000, 24900));
        self::assertSame(250000, $service->savings(250000, 0));
    }

    /**
     * Signed, on purpose. A quiet month on a paid tier can cost more than a
     * quarter of very little, and a panel that clamped this at zero would be
     * advertising rather than reporting.
     */
    public function testSavingsGoNegativeInAThinMonth(): void
    {
        self::assertSame(-14900, $this->service()->savings(10000, 24900));
    }

    /**
     * An unset custom fee is not a free month. Savings against a fee nobody has
     * decided is the whole commission, which is the most honest thing that can
     * be said before the rate is agreed.
     */
    public function testSavingsAgainstAnUndecidedFeeAreTheWholeCommission(): void
    {
        self::assertSame(250000, $this->service()->savings(250000, null));
    }

    // -----------------------------------------------------------------
    // What the panel needs
    // -----------------------------------------------------------------

    public function testTheNextTierIsTheOneAboveTheCurrentOne(): void
    {
        $service = $this->service();

        self::assertSame(41, (int) $service->nextTier(0)['min_orders']);
        self::assertSame(41, (int) $service->nextTier(40)['min_orders']);
        self::assertSame(101, (int) $service->nextTier(41)['min_orders']);
        self::assertSame(501, (int) $service->nextTier(500)['min_orders']);
        self::assertNull($service->nextTier(501), 'there is nothing above the custom tier');
    }

    public function testOrdersToTheNextTierCountsDownToTheBoundary(): void
    {
        $service = $this->service();

        self::assertSame(41, $service->ordersToNextTier(0));
        self::assertSame(1, $service->ordersToNextTier(40));
        self::assertSame(60, $service->ordersToNextTier(41));
        self::assertNull($service->ordersToNextTier(600));
    }

    public function testProgressFillsTheBarAcrossTheCurrentTier(): void
    {
        $service = $this->service();

        self::assertSame(0, $service->progressToNextTier(0));
        self::assertSame(49, $service->progressToNextTier(20), '20 of the 41 that reach the next tier');
        self::assertSame(98, $service->progressToNextTier(40));
        self::assertSame(100, $service->progressToNextTier(600), 'the top tier is full by definition');
    }

    public function testTheFreeThresholdIsReadOffTheScale(): void
    {
        self::assertSame(41, $this->service()->freeThreshold());
    }

    /**
     * The label an invoice line carries, built from the row's own boundaries so
     * it cannot drift from the scale it describes.
     */
    public function testATierIsNamedByItsOwnBoundaries(): void
    {
        $service = $this->service();

        self::assertSame('0–40 orders', TierBillingService::tierName($service->tierFor(0)));
        self::assertSame('41–100 orders', TierBillingService::tierName($service->tierFor(41)));
        self::assertSame('501+ orders', TierBillingService::tierName($service->tierFor(900)));
        self::assertSame('Monthly fee', TierBillingService::tierName(null));
    }

    // -----------------------------------------------------------------
    // A scale that is not the seeded one
    // -----------------------------------------------------------------

    /**
     * Nothing in the service knows the seeded numbers. An admin who rewrites
     * the scale rewrites every answer, which is what "tiers are read from the
     * table" has to mean to be worth saying.
     */
    public function testAnEditedScaleChangesEveryAnswer(): void
    {
        $service = new TierBillingService($this->tiers([
            [0, 9, 0, 0],
            [10, null, 5000, 0],
        ]), self::COMPARISON_PCT);

        self::assertSame(0, $service->feeFor($this->restaurant(), 9));
        self::assertSame(5000, $service->feeFor($this->restaurant(), 10));
        self::assertSame(5000, $service->feeFor($this->restaurant(), 10000));
        self::assertSame(10, $service->freeThreshold());
        self::assertNull($service->ordersToNextTier(10));
    }

    /**
     * A gap in the scale is a misconfiguration, and the honest answer is that
     * there is no tier — not the nearest one, which would bill somebody for a
     * rate nobody wrote down.
     */
    public function testACountOutsideEveryTierHasNoTierAndNoFee(): void
    {
        $service = new TierBillingService($this->tiers([[10, 20, 5000, 0]]), self::COMPARISON_PCT);

        self::assertNull($service->tierFor(21));
        self::assertNull($service->feeFor($this->restaurant(), 21));
        self::assertSame(0, $service->discountFor($this->restaurant(), 21));
    }

    public function testAnEmptyScaleQuotesNothing(): void
    {
        $service = new TierBillingService([], self::COMPARISON_PCT);

        self::assertNull($service->tierFor(50));
        self::assertNull($service->feeFor($this->restaurant(), 50));
        self::assertNull($service->freeThreshold());
        self::assertSame(100, $service->progressToNextTier(50));
    }

    /**
     * The comparison rate is a setting too.
     */
    public function testTheComparisonRateComesFromTheSettingNotFromThisClass(): void
    {
        $service = new TierBillingService($this->tiers(self::SEED_TIERS), '0.30');

        self::assertSame(300000, $service->commissionEquivalent(1000000));
    }

    // -----------------------------------------------------------------
    // Periods
    // -----------------------------------------------------------------

    public function testThePeriodNameIsTheMonthAPersonWouldSay(): void
    {
        self::assertSame('March 2026', TierBillingService::periodName('2026-03'));
        self::assertSame('January 2026', TierBillingService::periodName('2026-01'));
    }

    public function testTheBillingRunLooksAtTheMonthThatJustEnded(): void
    {
        // 06:00 Eastern on the 1st of March, which is 11:00 UTC.
        $firstOfMarch = new \DateTimeImmutable('2026-03-01 11:00:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-02', TierBillingService::previousPeriod($firstOfMarch));
        self::assertSame('2026-03', TierBillingService::currentPeriod($firstOfMarch));
    }

    /**
     * The zone decides which month it is, not the server's clock.
     *
     * 01:00 UTC on the 1st of March is still 8pm Eastern on the last day of
     * February. A run that fired then is billing January by the wall clock in
     * Tallahassee, and the period has to agree with the wall clock or the
     * orders counted into it will not.
     */
    public function testThePeriodIsResolvedInTheBillingZone(): void
    {
        $lateFebruaryEastern = new \DateTimeImmutable('2026-03-01 01:00:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-02', TierBillingService::currentPeriod($lateFebruaryEastern));
        self::assertSame('2026-01', TierBillingService::previousPeriod($lateFebruaryEastern));
    }

    /**
     * "First day of last month" rather than "-1 month", which on the 31st of
     * March means the 3rd of March and bills the wrong month entirely.
     */
    public function testThePreviousPeriodSurvivesAShortMonth(): void
    {
        $endOfMarch = new \DateTimeImmutable('2026-03-31 12:00:00', new \DateTimeZone('America/New_York'));

        self::assertSame('2026-02', TierBillingService::previousPeriod($endOfMarch));
    }

    /**
     * A thirteenth month is refused rather than rolled into the next year,
     * which is what createFromFormat does if nobody checks.
     */
    public function testAnImpossiblePeriodIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TierBillingService::periodName('2026-13');
    }

    public function testRubbishIsNotAPeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TierBillingService::periodName('last month');
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function service(): TierBillingService
    {
        return new TierBillingService($this->tiers(self::SEED_TIERS), self::COMPARISON_PCT);
    }

    /**
     * The tier rows as the table hands them over: strings from the driver,
     * NULL for an open-ended maximum.
     *
     * @param list<array{0: int, 1: int|null, 2: int, 3: int}> $rows
     * @return list<array<string, mixed>>
     */
    private function tiers(array $rows): array
    {
        $tiers = [];

        foreach ($rows as $index => [$min, $max, $fee, $isCustom]) {
            $tiers[] = [
                'id' => $index + 1,
                'min_orders' => (string) $min,
                'max_orders' => $max === null ? null : (string) $max,
                'fee_cents' => (string) $fee,
                'is_custom' => (string) $isCustom,
                'sort' => (string) $index,
            ];
        }

        return $tiers;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function restaurant(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'Railroad Square Tacos',
            'founding_discount_pct' => '0.0000',
            'custom_fee_cents' => null,
        ], $overrides);
    }
}
