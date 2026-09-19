<?php

namespace Keel\App\Services\Billing;

use Keel\App\Models\Order;
use Keel\App\Models\RestaurantFeeTier;
use Keel\App\Services\Pricing\Money;
use Keel\App\Services\Settings;

/**
 * What a restaurant owes for a month, and what it did not pay instead.
 *
 * The one place the tier scale is turned into a number. Everything here works
 * from the rows in restaurant_fee_tiers — the scale is handed to the
 * constructor, not written down in this file — because the spec forbids a
 * hardcoded price anywhere, and because an admin who edits the scale must not
 * have to edit code as well.
 *
 * The split is deliberate and matches PricingService: the arithmetic takes the
 * tier rows and the comparison rate as arguments and touches nothing else, so a
 * unit test can walk every boundary without a database. Only countOrders() and
 * statementFor() go to the tables, and they go only for facts — how many orders
 * were delivered, and what they sold for.
 *
 * Two things this class is careful about:
 *
 *   - the custom tier has no fee of its own. Its figure lives on the
 *     restaurant, an admin puts it there by hand, and until they have, feeFor()
 *     answers null rather than nothing-to-pay. Those are different sentences
 *     and the billing job acts on them differently.
 *   - comparison_commission_pct is display-only. It is never deducted from
 *     anything; it exists so a restaurant can see what a marketplace that does
 *     take a commission would have taken, which is the whole argument FairPlate
 *     is making. Nothing in this file subtracts it from a payout.
 */
class TierBillingService
{
    /**
     * @param list<array<string, mixed>> $tiers restaurant_fee_tiers, in order
     * @param string $comparisonCommissionPct an exact decimal, never a float
     */
    public function __construct(
        private array $tiers,
        private string $comparisonCommissionPct
    ) {
    }

    /**
     * The scale as the database has it today.
     */
    public static function fromTables(): self
    {
        return new self(RestaurantFeeTier::all(), Settings::decimal('comparison_commission_pct'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tiers(): array
    {
        return $this->tiers;
    }

    // -----------------------------------------------------------------
    // The facts
    // -----------------------------------------------------------------

    /**
     * Delivered orders in one America/New_York calendar month.
     *
     * The zone matters and is not decoration: an order delivered at 11:30pm
     * Eastern on the last day of a month is stored as the next month in UTC,
     * and counting it there would move it into a month nobody who was present
     * would agree it belonged to. Order resolves the boundary against the real
     * zone rather than a fixed offset, so a daylight-saving change does not
     * misfile the orders either side of it.
     */
    public function countOrders(array $restaurant, string $period): int
    {
        return Order::completedCountForPeriod((int) $restaurant['id'], $period);
    }

    // -----------------------------------------------------------------
    // The arithmetic
    // -----------------------------------------------------------------

    /**
     * The tier an order count lands in, or null when the scale is empty.
     *
     * A NULL max_orders is open-ended, which is the custom tier at the top.
     */
    public function tierFor(int $orders): ?array
    {
        foreach ($this->tiers as $tier) {
            $min = (int) $tier['min_orders'];
            $max = $tier['max_orders'] === null ? null : (int) $tier['max_orders'];

            if ($orders >= $min && ($max === null || $orders <= $max)) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * What this restaurant pays for a month of this size, discount applied.
     *
     * Null means "nobody has decided yet", which is the custom tier before an
     * admin has set custom_fee_cents. The billing job turns that into a
     * statement that needs review and an alert. It must never become an invoice
     * for zero, which would tell a restaurant doing six hundred orders a month
     * that it owes nothing.
     */
    public function feeFor(array $restaurant, int $orders): ?int
    {
        $listCents = $this->listFeeFor($restaurant, $orders);

        if ($listCents === null) {
            return null;
        }

        return $listCents - $this->discountOn($restaurant, $listCents);
    }

    /**
     * The tier fee before this restaurant's founding discount.
     */
    public function listFeeFor(array $restaurant, int $orders): ?int
    {
        $tier = $this->tierFor($orders);

        if ($tier === null) {
            return null;
        }

        if ((int) ($tier['is_custom'] ?? 0) === 1) {
            $custom = $restaurant['custom_fee_cents'] ?? null;

            return $custom === null ? null : (int) $custom;
        }

        return (int) $tier['fee_cents'];
    }

    /**
     * What the founding discount takes off, in cents.
     *
     * Rounded to the nearest cent, halves away from zero — Money::percentOf is
     * the same rounding tax and every other percentage in FairPlate uses, done
     * on an exact decimal rather than a float. A 20% discount on the 41–100
     * tier is 4980 off 24900, leaving 19920.
     */
    public function discountFor(array $restaurant, int $orders): int
    {
        $listCents = $this->listFeeFor($restaurant, $orders);

        return $listCents === null ? 0 : $this->discountOn($restaurant, $listCents);
    }

    /**
     * What a commission marketplace would have taken out of these sales.
     *
     * Rounded once, at the end, on the month's total — not per order. The spec
     * states it as "sum of subtotals × comparison_commission_pct", and summing a
     * rounded per-order figure would drift from that by a few cents in a number
     * whose only job is to be checkable by hand.
     */
    public function commissionEquivalent(int $salesSubtotalCents): int
    {
        return Money::percentOf($salesSubtotalCents, $this->comparisonCommissionPct);
    }

    /**
     * The commission that was not charged, less the fee that was.
     *
     * Signed on purpose. A quiet month on an undiscounted tier can cost more
     * than a quarter of very little, and a panel that clamped this at zero
     * would be advertising rather than reporting.
     */
    public function savings(int $commissionEquivalentCents, ?int $feeCents): int
    {
        return $commissionEquivalentCents - (int) $feeCents;
    }

    /**
     * The tier above the one this count is in, or null at the top.
     */
    public function nextTier(int $orders): ?array
    {
        $tier = $this->tierFor($orders);
        $floor = $tier === null ? null : (int) $tier['min_orders'];

        foreach ($this->tiers as $candidate) {
            $min = (int) $candidate['min_orders'];

            if ($floor === null ? $min > $orders : $min > $floor) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * How many more orders until the fee changes, or null at the top tier.
     */
    public function ordersToNextTier(int $orders): ?int
    {
        $next = $this->nextTier($orders);

        return $next === null ? null : max(0, (int) $next['min_orders'] - $orders);
    }

    /**
     * How far through the current tier a count is, 0–100, for the progress bar.
     *
     * The top tier is full by definition: there is no boundary left to move
     * towards, and a bar sitting at zero would read as no progress rather than
     * as nowhere further to go.
     */
    public function progressToNextTier(int $orders): int
    {
        $next = $this->nextTier($orders);

        if ($next === null) {
            return 100;
        }

        $tier = $this->tierFor($orders);
        $floor = $tier === null ? 0 : (int) $tier['min_orders'];
        $ceiling = (int) $next['min_orders'];

        if ($ceiling <= $floor) {
            return 100;
        }

        return max(0, min(100, (int) round((($orders - $floor) / ($ceiling - $floor)) * 100)));
    }

    /**
     * The first order count that costs anything.
     *
     * The spec's "months under 41 orders are free" as a number read off the
     * scale rather than written down here, so a screen can say it without
     * hardcoding the boundary the constraint forbids hardcoding. Null when
     * every tier is free, which is a scale nobody has finished configuring.
     */
    public function freeThreshold(): ?int
    {
        foreach ($this->tiers as $tier) {
            $isCustom = (int) ($tier['is_custom'] ?? 0) === 1;

            if ($isCustom || (int) $tier['fee_cents'] > 0) {
                return (int) $tier['min_orders'];
            }
        }

        return null;
    }

    /**
     * A tier as an invoice line names it: "41–100 orders", "501+ orders".
     *
     * Built from the row's own boundaries rather than from a name column, so a
     * label can never drift from the scale it describes — an admin who moves a
     * boundary moves the label with it, and there is no second place to edit.
     */
    public static function tierName(?array $tier): string
    {
        if ($tier === null) {
            return 'Monthly fee';
        }

        $min = (int) $tier['min_orders'];

        if ($tier['max_orders'] === null) {
            return $min . '+ orders';
        }

        return $min . '–' . (int) $tier['max_orders'] . ' orders';
    }

    /**
     * A YYYY-MM period as a person reads it: "March 2026".
     *
     * The round trip is the validation, and it is not belt and braces.
     * createFromFormat does not refuse a thirteenth month, it rolls it into the
     * next year — so "2026-13" parses happily and would be displayed as
     * "January 2027", which is a statement page confidently labelling a month
     * that is not the one it is showing. Formatting the parsed date back and
     * comparing is the only check that catches it.
     */
    public static function periodName(string $period): string
    {
        $month = \DateTimeImmutable::createFromFormat('Y-m-d', $period . '-01');

        if ($month === false || $month->format('Y-m') !== $period) {
            throw new \InvalidArgumentException("Billing period \"{$period}\" is not a valid YYYY-MM month.");
        }

        return $month->format('F Y');
    }

    /**
     * The America/New_York calendar month that has just ended.
     */
    public static function previousPeriod(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now');

        return $now->setTimezone(new \DateTimeZone('America/New_York'))
            ->modify('first day of last month')
            ->format('Y-m');
    }

    /**
     * The America/New_York calendar month in progress.
     */
    public static function currentPeriod(?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now');

        return $now->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m');
    }

    // -----------------------------------------------------------------
    // The whole picture
    // -----------------------------------------------------------------

    /**
     * Every number one month owes, read from the tables.
     *
     * The same shape whether the job is billing it or the kitchen panel is
     * projecting it live, so the figures a restaurant watches accrue all month
     * are the figures it is invoiced for by construction, rather than by two
     * calculations that have to be kept in agreement.
     *
     * @return array{
     *     period: string, orders: int, sales_subtotal_cents: int, tier: array|null,
     *     tier_id: int|null, tier_name: string, list_fee_cents: int|null,
     *     discount_cents: int, fee_cents: int|null, commission_equiv_cents: int,
     *     savings_cents: int, next_tier: array|null, orders_to_next_tier: int|null,
     *     progress_pct: int, free_threshold: int|null
     * }
     */
    public function statementFor(array $restaurant, string $period): array
    {
        $sales = Order::deliveredSalesForPeriod((int) $restaurant['id'], $period);
        $orders = $sales['orders'];

        $tier = $this->tierFor($orders);
        $feeCents = $this->feeFor($restaurant, $orders);
        $commissionEquivalent = $this->commissionEquivalent($sales['subtotal_cents']);

        return [
            'period' => $period,
            'orders' => $orders,
            'sales_subtotal_cents' => $sales['subtotal_cents'],
            'tier' => $tier,
            'tier_id' => $tier === null ? null : (int) $tier['id'],
            'tier_name' => self::tierName($tier),
            'list_fee_cents' => $this->listFeeFor($restaurant, $orders),
            'discount_cents' => $this->discountFor($restaurant, $orders),
            'fee_cents' => $feeCents,
            'commission_equiv_cents' => $commissionEquivalent,
            'savings_cents' => $this->savings($commissionEquivalent, $feeCents),
            'next_tier' => $this->nextTier($orders),
            'orders_to_next_tier' => $this->ordersToNextTier($orders),
            'progress_pct' => $this->progressToNextTier($orders),
            'free_threshold' => $this->freeThreshold(),
        ];
    }

    private function discountOn(array $restaurant, int $listCents): int
    {
        $rate = trim((string) ($restaurant['founding_discount_pct'] ?? '0'));

        return $rate === '' ? 0 : Money::percentOf($listCents, $rate);
    }
}
