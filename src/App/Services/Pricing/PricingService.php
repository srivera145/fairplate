<?php

namespace Keel\App\Services\Pricing;

use Keel\App\Models\Membership;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Models\Special;
use Keel\App\Services\Routing\RoutingService;
use Keel\App\Services\Settings;

/**
 * Every price FairPlate charges, computes or promises.
 *
 * The spec allows pricing math in exactly one place, and this is it. Nothing
 * outside this namespace may add a fee, apply a rate or round a cent; a
 * controller that wants a number asks for a Breakdown and reads it.
 *
 * Three ideas hold the whole thing together:
 *
 * A quote prices twice. The customer is shown the total with no wait pay,
 * because the driver has not waited yet; the card is authorized for the total
 * with wait pay at its cap, because that is the worst the order can become.
 * The authorization is therefore never smaller than the estimate, and the
 * capture at delivery can never exceed the authorization.
 *
 * A quote freezes its inputs. Every setting it read, the restaurant's tax
 * rate, the route miles and whether the customer was a member that minute go
 * into a snapshot that is written onto the order. finalize() and
 * tipAdjustment() read only that snapshot. An admin raising the platform fee
 * this afternoon cannot restate this morning's orders, which is the spec's
 * rule and also the only way a receipt stays true.
 *
 * The customer covers every per-order cost. The gross-up ceilings so the
 * service fee always covers the real card cost, which is what makes "no order
 * ever costs FairPlate money" arithmetic rather than aspiration.
 */
final class PricingService
{
    /** Pricing settings frozen onto every order. */
    public const SETTING_KEYS = [
        'driver_base_cents',
        'driver_per_mile_cents',
        'driver_min_payout_cents',
        'driver_wait_free_minutes',
        'driver_wait_per_min_cents',
        'driver_wait_cap_cents',
        'processing_pct',
        'processing_fixed_cents',
        'platform_fee_cents',
    ];

    /** Specials run on the restaurant's clock, not the server's. */
    public const TIMEZONE = 'America/New_York';

    /** Route miles are stored as DECIMAL(6,2); mileage pay is computed on the same grid. */
    private const MILE_SCALE = 100;

    /**
     * @param RoutingService|null $routing Required by quote(); finalize() and
     *        tipAdjustment() work from a frozen snapshot and never route.
     * @param array<string, mixed>|null $settings Live settings are read at
     *        quote time when this is null. Tests pass a snapshot so the money
     *        math needs no database.
     */
    public function __construct(
        private readonly ?RoutingService $routing = null,
        private readonly ?array $settings = null,
    ) {
    }

    // -----------------------------------------------------------------
    // Quoting
    // -----------------------------------------------------------------

    /**
     * Prices a cart both ways: what the customer is shown, and what the card is
     * authorized for.
     *
     * $cart accepts:
     *   items[]: price_cents, quantity, menu_item_id, taxable (default true),
     *            options[]: price_delta_cents
     *   tip_cents
     *
     * $restaurant needs lat, lng and tax_rate. Pass a `specials` key to price
     * against a known list; otherwise the restaurant's active specials are
     * loaded by id. $customer works the same way: pass `is_member` to state it
     * outright, or an `id` to have the membership looked up.
     *
     * @return array{
     *     route_miles: float,
     *     is_member: bool,
     *     settings_snapshot: array<string, mixed>,
     *     subtotal: array<string, mixed>,
     *     estimate: Breakdown,
     *     authorization: Breakdown
     * }
     */
    public function quote(
        array $cart,
        array $restaurant,
        array $address,
        array $customer,
        ?\DateTimeImmutable $at = null
    ): array {
        if ($this->routing === null) {
            throw new PricingException('Quoting needs a RoutingService.');
        }

        $at = $this->localTime($at);
        $settings = $this->liveSettings();

        $routeMiles = $this->routing->miles(
            $this->coordinate($restaurant, 'lat', 'restaurant'),
            $this->coordinate($restaurant, 'lng', 'restaurant'),
            $this->coordinate($address, 'lat', 'address'),
            $this->coordinate($address, 'lng', 'address'),
        );

        if ($routeMiles < 0) {
            throw new PricingException('Route miles cannot be negative.');
        }

        $isMember = $this->isMember($customer);
        $taxRate = $this->taxRate($restaurant);

        // Everything the two breakdowns, and every later recomputation, are
        // allowed to know. After this line live settings are out of the picture.
        $snapshot = $settings + [
            'tax_rate' => $taxRate,
            'route_miles' => $routeMiles,
            'is_member' => $isMember,
            'quoted_at' => $at->format(\DateTimeInterface::ATOM),
        ];

        $subtotal = $this->subtotal($cart, $restaurant, $at);

        $parts = [
            'subtotal' => $subtotal['subtotal_cents'],
            'tax' => Money::percentOf($subtotal['taxable_cents'], $taxRate),
            'tip' => $this->tip($cart),
            'platform_fee' => $isMember ? 0 : (int) $snapshot['platform_fee_cents'],
        ] + $this->driverPay($routeMiles, $snapshot);

        $estimate = $this->assemble(
            $parts,
            0,
            0,
            $snapshot,
            OrderPriceBreakdown::STAGE_ESTIMATE
        );

        $authorization = $this->assemble(
            $parts,
            (int) $snapshot['driver_wait_cap_cents'],
            $this->minutesToReachCap($snapshot),
            $snapshot,
            OrderPriceBreakdown::STAGE_AUTHORIZED
        );

        if ($authorization->total() < $estimate->total()) {
            throw new PricingException('The authorization came out below the estimate.');
        }

        return [
            'route_miles' => $routeMiles,
            'is_member' => $isMember,
            'settings_snapshot' => $snapshot,
            'subtotal' => $subtotal,
            'estimate' => $estimate,
            'authorization' => $authorization,
        ];
    }

    // -----------------------------------------------------------------
    // Settling
    // -----------------------------------------------------------------

    /**
     * The breakdown to capture at delivery, from the order's frozen snapshot.
     *
     * Only the wait pay is recomputed. Subtotal, tax, driver guarantee, tip and
     * platform fee are read back exactly as authorized: the guarantee a driver
     * accepted must not move, and the rest was already charged for.
     *
     * Pass the frozen row as $order['breakdown'] to settle without a database
     * read; otherwise the authorized row is loaded by order id.
     */
    public function finalize(array $order, int $actualWaitMinutes): Breakdown
    {
        $row = $this->frozenRow($order, [
            OrderPriceBreakdown::STAGE_AUTHORIZED,
            OrderPriceBreakdown::STAGE_ESTIMATE,
        ]);

        $snapshot = OrderPriceBreakdown::settingsSnapshot($row);

        if ($snapshot === []) {
            throw new PricingException('The frozen breakdown carries no settings snapshot.');
        }

        $waitMinutes = max(0, $actualWaitMinutes);

        $final = $this->assemble(
            [
                'subtotal' => (int) ($row['subtotal_cents'] ?? 0),
                'tax' => (int) ($row['tax_cents'] ?? 0),
                'driver_base' => (int) ($row['driver_base_cents'] ?? 0),
                'driver_mileage' => (int) ($row['driver_mileage_cents'] ?? 0),
                'driver_guaranteed' => (int) ($row['driver_guaranteed_cents'] ?? 0),
                'tip' => (int) ($row['tip_cents'] ?? 0),
                'platform_fee' => (int) ($row['platform_fee_cents'] ?? 0),
            ],
            $this->waitPay($waitMinutes, $snapshot),
            $waitMinutes,
            $snapshot,
            OrderPriceBreakdown::STAGE_FINAL
        );

        $authorized = $order['authorized_cents'] ?? null;

        if ($authorized !== null && $final->total() > (int) $authorized) {
            throw new PricingException(sprintf(
                'The final total %d exceeds the authorization %d.',
                $final->total(),
                (int) $authorized
            ));
        }

        return $final;
    }

    /**
     * The separate charge for a tip raised after delivery.
     *
     * The delta is grossed up on its own, so the driver keeps the whole
     * increase and the processing on it is still covered. Every line here is
     * the tip and the fee that carries it — nothing else is re-charged.
     */
    public function tipAdjustment(array $order, int $deltaCents): Breakdown
    {
        if ($deltaCents <= 0) {
            throw new PricingException('A tip adjustment charge needs a positive delta.');
        }

        $row = $this->frozenRow($order, [
            OrderPriceBreakdown::STAGE_FINAL,
            OrderPriceBreakdown::STAGE_AUTHORIZED,
            OrderPriceBreakdown::STAGE_ESTIMATE,
        ]);

        $snapshot = OrderPriceBreakdown::settingsSnapshot($row);

        if ($snapshot === []) {
            throw new PricingException('The frozen breakdown carries no settings snapshot.');
        }

        $charge = Money::grossUp(
            $deltaCents,
            (int) $snapshot['processing_fixed_cents'],
            (string) $snapshot['processing_pct']
        );

        $breakdown = new Breakdown(
            [
                Breakdown::TIP => $deltaCents,
                Breakdown::SERVICE_FEE => $charge - $deltaCents,
            ],
            $charge,
            [
                'stage' => 'tip_adjustment',
                'settings_snapshot' => $snapshot,
            ]
        );

        $breakdown->assertBalanced();

        return $breakdown;
    }

    // -----------------------------------------------------------------
    // The formulas, public for offer cards and receipts
    // -----------------------------------------------------------------

    /**
     * max(base + round(per_mile × miles), min_payout).
     *
     * This is the number on the driver's offer card, and it is a promise: once
     * accepted it never drops.
     *
     * @param array<string, mixed> $snapshot
     */
    public function driverGuaranteed(float $miles, array $snapshot): int
    {
        return $this->driverPay($miles, $snapshot)['driver_guaranteed'];
    }

    /**
     * The guarantee and the base and mileage it was built from.
     *
     * They need not sum to the guarantee — the minimum payout is what makes a
     * half-mile run worth taking — which is why only the guarantee is a charge
     * line and these two ride along as detail.
     *
     * @param array<string, mixed> $snapshot
     * @return array{driver_base: int, driver_mileage: int, driver_guaranteed: int}
     */
    public function driverPay(float $miles, array $snapshot): array
    {
        $base = (int) $snapshot['driver_base_cents'];
        $perMile = (int) $snapshot['driver_per_mile_cents'];
        $minimum = (int) $snapshot['driver_min_payout_cents'];

        // Miles land on the hundredth they are stored at before they touch
        // money, so a float never reaches a cent.
        $hundredths = (int) round(max(0.0, $miles) * self::MILE_SCALE);
        $mileage = intdiv(($perMile * $hundredths) + (self::MILE_SCALE / 2), self::MILE_SCALE);

        return [
            'driver_base' => $base,
            'driver_mileage' => $mileage,
            'driver_guaranteed' => max($base + $mileage, $minimum),
        ];
    }

    /**
     * min(max(0, minutes − free) × per_min, cap).
     *
     * Minutes are whole minutes from arrived_at_restaurant to picked_up.
     *
     * @param array<string, mixed> $snapshot
     */
    public function waitPay(int $minutes, array $snapshot): int
    {
        $free = (int) $snapshot['driver_wait_free_minutes'];
        $perMinute = (int) $snapshot['driver_wait_per_min_cents'];
        $cap = (int) $snapshot['driver_wait_cap_cents'];

        return min(max(0, $minutes - $free) * $perMinute, $cap);
    }

    /**
     * The food subtotal with active specials applied.
     *
     * Specials apply to the item price, then options are added on top: a "$5
     * burrito" special is a price for the burrito, not for the burrito plus
     * guacamole. Where several specials could apply to one item only the best
     * one does — discounts do not stack, because a customer cannot be shown two
     * prices for the same thing. An order-wide special is then applied to what
     * is left and split across the lines by largest remainder, so the taxable
     * share of a discount lands where the tax is actually owed.
     *
     * @return array{
     *     subtotal_cents: int,
     *     taxable_cents: int,
     *     discount_cents: int,
     *     items: list<array<string, mixed>>
     * }
     */
    public function subtotal(array $cart, array $restaurant, ?\DateTimeImmutable $at = null): array
    {
        $at = $this->localTime($at);
        $running = $this->runningSpecials($restaurant, $at);

        $lines = [];
        $grossCents = 0;
        $listCents = 0;

        foreach ($this->items($cart) as $item) {
            $quantity = max(0, (int) ($item['quantity'] ?? 1));
            $basePrice = max(0, (int) ($item['price_cents'] ?? 0));
            $optionsCents = $this->optionsCents($item);
            $menuItemId = isset($item['menu_item_id']) ? (int) $item['menu_item_id'] : null;

            $special = $this->bestItemSpecial($running, $menuItemId, $basePrice);
            $discountPerUnit = $special === null ? 0 : $this->unitDiscount($basePrice, $special);

            $unitCents = max(0, ($basePrice - $discountPerUnit) + $optionsCents);
            $lineCents = $unitCents * $quantity;

            $lines[] = [
                'menu_item_id' => $menuItemId,
                'quantity' => $quantity,
                'unit_cents' => $unitCents,
                'gross_cents' => $lineCents,
                'line_cents' => $lineCents,
                'discount_cents' => $discountPerUnit * $quantity,
                'taxable' => (bool) ($item['taxable'] ?? true),
                'special_id' => $special === null ? null : (int) ($special['id'] ?? 0),
            ];

            $grossCents += $lineCents;
            $listCents += max(0, $basePrice + $optionsCents) * $quantity;
        }

        $orderSpecial = $this->bestOrderSpecial($running, $grossCents);
        $orderDiscount = $orderSpecial === null ? 0 : $this->orderDiscount($grossCents, $orderSpecial);

        if ($orderDiscount > 0) {
            $allocated = Money::allocate(
                $orderDiscount,
                array_map(static fn (array $line): int => $line['gross_cents'], $lines)
            );

            foreach ($allocated as $index => $share) {
                $lines[$index]['line_cents'] -= $share;
                $lines[$index]['discount_cents'] += $share;
            }
        }

        $subtotalCents = 0;
        $taxableCents = 0;

        foreach ($lines as $line) {
            $subtotalCents += $line['line_cents'];

            if ($line['taxable']) {
                $taxableCents += $line['line_cents'];
            }
        }

        return [
            'subtotal_cents' => $subtotalCents,
            'taxable_cents' => $taxableCents,
            'discount_cents' => $listCents - $subtotalCents,
            'items' => $lines,
        ];
    }

    /**
     * The live pricing settings, as they would be snapshotted onto an order.
     *
     * @return array<string, mixed>
     */
    public function liveSettings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        return Settings::snapshot(self::SETTING_KEYS);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Turns priced parts plus a wait pay into a balanced Breakdown.
     *
     * This is the spec's last two formulas and the only place they appear:
     *
     *   S = subtotal + tax + driver_guaranteed + wait_pay + tip + platform_fee
     *   charge_total = ceil((S + processing_fixed_cents) / (1 − processing_pct))
     *   service_fee = charge_total − S
     *
     * @param array<string, int> $parts
     * @param array<string, mixed> $snapshot
     */
    private function assemble(
        array $parts,
        int $waitPayCents,
        int $waitMinutes,
        array $snapshot,
        string $stage
    ): Breakdown {
        $lines = [
            Breakdown::SUBTOTAL => $parts['subtotal'],
            Breakdown::TAX => $parts['tax'],
            Breakdown::DRIVER_PAY => $parts['driver_guaranteed'],
            Breakdown::WAIT_PAY => $waitPayCents,
            Breakdown::TIP => $parts['tip'],
            Breakdown::PLATFORM_FEE => $parts['platform_fee'],
        ];

        $chargeable = array_sum($lines);

        $total = Money::grossUp(
            $chargeable,
            (int) $snapshot['processing_fixed_cents'],
            (string) $snapshot['processing_pct']
        );

        $lines[Breakdown::SERVICE_FEE] = $total - $chargeable;

        $breakdown = new Breakdown($lines, $total, [
            'stage' => $stage,
            'driver_base_cents' => $parts['driver_base'],
            'driver_mileage_cents' => $parts['driver_mileage'],
            'wait_minutes' => $waitMinutes,
            'route_miles' => (float) ($snapshot['route_miles'] ?? 0.0),
            'is_member' => (bool) ($snapshot['is_member'] ?? false),
            'settings_snapshot' => $snapshot,
        ]);

        $breakdown->assertBalanced();

        return $breakdown;
    }

    /**
     * The frozen breakdown row to settle from, preferring the latest stage.
     *
     * @param list<string> $stages
     * @return array<string, mixed>
     */
    private function frozenRow(array $order, array $stages): array
    {
        if (isset($order['breakdown']) && is_array($order['breakdown'])) {
            return $order['breakdown'];
        }

        $orderId = (int) ($order['id'] ?? 0);

        if ($orderId <= 0) {
            throw new PricingException('Settling needs either a frozen breakdown or an order id.');
        }

        foreach ($stages as $stage) {
            $row = OrderPriceBreakdown::forStage($orderId, $stage);

            if ($row !== null) {
                return $row;
            }
        }

        throw new PricingException("Order {$orderId} has no priced breakdown to settle from.");
    }

    /**
     * The wait, in minutes, at which wait pay reaches its cap.
     *
     * The authorization is taken at the cap, and the breakdown records the
     * minutes that justify it so a receipt can explain the number.
     *
     * @param array<string, mixed> $snapshot
     */
    private function minutesToReachCap(array $snapshot): int
    {
        $free = (int) $snapshot['driver_wait_free_minutes'];
        $perMinute = (int) $snapshot['driver_wait_per_min_cents'];
        $cap = (int) $snapshot['driver_wait_cap_cents'];

        if ($perMinute <= 0 || $cap <= 0) {
            return $free;
        }

        return $free + (int) ceil($cap / $perMinute);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(array $cart): array
    {
        $items = $cart['items'] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    private function optionsCents(array $item): int
    {
        $options = $item['options'] ?? [];

        if (!is_array($options)) {
            return 0;
        }

        $cents = 0;

        foreach ($options as $option) {
            if (is_array($option)) {
                $cents += (int) ($option['price_delta_cents'] ?? 0);
                continue;
            }

            $cents += (int) $option;
        }

        return $cents;
    }

    private function tip(array $cart): int
    {
        $tip = (int) ($cart['tip_cents'] ?? 0);

        if ($tip < 0) {
            throw new PricingException('A tip cannot be negative.');
        }

        return $tip;
    }

    /**
     * The restaurant's active specials that are running at this local time.
     *
     * @return list<array<string, mixed>>
     */
    private function runningSpecials(array $restaurant, \DateTimeImmutable $at): array
    {
        if (array_key_exists('specials', $restaurant)) {
            $specials = is_array($restaurant['specials']) ? $restaurant['specials'] : [];
        } else {
            $restaurantId = (int) ($restaurant['id'] ?? 0);
            $specials = $restaurantId > 0 ? Special::activeForRestaurant($restaurantId) : [];
        }

        $isoWeekday = (int) $at->format('N');
        $localTime = $at->format('H:i:s');

        return array_values(array_filter(
            $specials,
            static fn (array $special): bool => Special::runsAt($special, $isoWeekday, $localTime)
        ));
    }

    /**
     * @param list<array<string, mixed>> $specials
     * @return array<string, mixed>|null
     */
    private function bestItemSpecial(array $specials, ?int $menuItemId, int $basePrice): ?array
    {
        if ($menuItemId === null || $menuItemId <= 0) {
            return null;
        }

        $best = null;
        $bestDiscount = 0;

        foreach ($specials as $special) {
            if ((int) ($special['menu_item_id'] ?? 0) !== $menuItemId) {
                continue;
            }

            $discount = $this->unitDiscount($basePrice, $special);

            if ($discount > $bestDiscount) {
                $best = $special;
                $bestDiscount = $discount;
            }
        }

        return $best;
    }

    /**
     * @param list<array<string, mixed>> $specials
     * @return array<string, mixed>|null
     */
    private function bestOrderSpecial(array $specials, int $subtotalCents): ?array
    {
        $best = null;
        $bestDiscount = 0;

        foreach ($specials as $special) {
            if (($special['menu_item_id'] ?? null) !== null) {
                continue;
            }

            $discount = $this->orderDiscount($subtotalCents, $special);

            if ($discount > $bestDiscount) {
                $best = $special;
                $bestDiscount = $discount;
            }
        }

        return $best;
    }

    /**
     * What one unit of an item comes off by, never below zero or above the price.
     *
     * @param array<string, mixed> $special
     */
    private function unitDiscount(int $basePrice, array $special): int
    {
        $discount = match ((string) ($special['type'] ?? '')) {
            Special::TYPE_PERCENT => Money::percentOf($basePrice, (string) ($special['value_pct'] ?? '0')),
            Special::TYPE_AMOUNT => (int) ($special['value_cents'] ?? 0),
            // A special price only ever lowers a price; a "special" that raised
            // one would break the promise that menu prices match in-store. A
            // price special with no price set is a half-filled admin form, not
            // a free item.
            Special::TYPE_PRICE => isset($special['value_cents'])
                ? $basePrice - (int) $special['value_cents']
                : 0,
            default => 0,
        };

        return max(0, min($discount, $basePrice));
    }

    /**
     * What a whole-order special comes off the subtotal by.
     *
     * A fixed special price makes no sense for a cart, so a price special with
     * no item attached is ignored rather than guessed at.
     *
     * @param array<string, mixed> $special
     */
    private function orderDiscount(int $subtotalCents, array $special): int
    {
        $discount = match ((string) ($special['type'] ?? '')) {
            Special::TYPE_PERCENT => Money::percentOf($subtotalCents, (string) ($special['value_pct'] ?? '0')),
            Special::TYPE_AMOUNT => (int) ($special['value_cents'] ?? 0),
            default => 0,
        };

        return max(0, min($discount, $subtotalCents));
    }

    private function isMember(array $customer): bool
    {
        if (array_key_exists('is_member', $customer)) {
            return (bool) $customer['is_member'];
        }

        $customerId = (int) ($customer['id'] ?? 0);

        return $customerId > 0 && Membership::isActiveFor($customerId);
    }

    private function taxRate(array $restaurant): string
    {
        $rate = $restaurant['tax_rate'] ?? null;

        if ($rate === null || $rate === '') {
            throw new PricingException('The restaurant has no tax_rate to price against.');
        }

        // DECIMAL columns come back as exact strings; keep them that way.
        return is_float($rate) ? rtrim(rtrim(sprintf('%.4F', $rate), '0'), '.') : (string) $rate;
    }

    private function coordinate(array $row, string $key, string $label): float
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            throw new PricingException("The {$label} has no {$key} to route from.");
        }

        return (float) $value;
    }

    private function localTime(?\DateTimeImmutable $at): \DateTimeImmutable
    {
        $zone = new \DateTimeZone(self::TIMEZONE);

        return $at === null
            ? new \DateTimeImmutable('now', $zone)
            : $at->setTimezone($zone);
    }
}
