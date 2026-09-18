<?php

namespace Keel\App\Services\Pricing;

use Keel\App\Models\OrderPriceBreakdown;

/**
 * One priced set of customer charge lines.
 *
 * The lines are exactly the spec's charge lines and nothing else. Driver base
 * and per-mile are not lines: the minimum payout means they need not sum to the
 * guarantee, so they ride along as detail and only driver_pay is charged. Same
 * for route miles and the settings snapshot — context the receipt and the order
 * row need, never money.
 *
 * total() is the amount the card is charged, carried rather than recomputed, so
 * assertBalanced() is a real check: it compares the lines against the number
 * that was actually grossed up. Every breakdown this codebase produces is
 * asserted before it leaves PricingService.
 */
final class Breakdown
{
    public const SUBTOTAL = 'subtotal';
    public const TAX = 'tax';
    public const DRIVER_PAY = 'driver_pay';
    public const WAIT_PAY = 'wait_pay';
    public const TIP = 'tip';
    public const PLATFORM_FEE = 'platform_fee';
    public const SERVICE_FEE = 'service_fee';

    /** Charge lines, in the order a customer reads them. */
    public const LINE_ORDER = [
        self::SUBTOTAL,
        self::TAX,
        self::DRIVER_PAY,
        self::WAIT_PAY,
        self::TIP,
        self::PLATFORM_FEE,
        self::SERVICE_FEE,
    ];

    /**
     * The only labels these lines are ever shown under.
     *
     * "Service fee" is fixed by the spec: never "surcharge", never "card fee".
     * Keeping the wording here means no view can invent its own.
     */
    public const LABELS = [
        self::SUBTOTAL => 'Food subtotal',
        self::TAX => 'Sales tax',
        self::DRIVER_PAY => 'Driver pay',
        self::WAIT_PAY => 'Wait pay',
        self::TIP => 'Tip',
        self::PLATFORM_FEE => 'Platform fee',
        self::SERVICE_FEE => 'Service fee',
    ];

    /** @var array<string, int> */
    private array $lines;

    /**
     * @param array<string, int> $lines
     * @param array<string, mixed> $meta
     */
    public function __construct(array $lines, private readonly int $total, private readonly array $meta = [])
    {
        $ordered = [];

        foreach (self::LINE_ORDER as $key) {
            if (!array_key_exists($key, $lines)) {
                continue;
            }

            if (!is_int($lines[$key])) {
                throw new PricingException("Breakdown line \"{$key}\" must be integer cents.");
            }

            $ordered[$key] = $lines[$key];
            unset($lines[$key]);
        }

        if ($lines !== []) {
            throw new PricingException('Unknown breakdown line(s): ' . implode(', ', array_keys($lines)) . '.');
        }

        $this->lines = $ordered;
    }

    /**
     * @return array<string, int>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function line(string $key): int
    {
        return $this->lines[$key] ?? 0;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->lines);
    }

    /**
     * The amount charged to the card.
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    public function metaValue(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function stage(): ?string
    {
        $stage = $this->meta['stage'] ?? null;

        return is_string($stage) ? $stage : null;
    }

    public function routeMiles(): float
    {
        return (float) ($this->meta['route_miles'] ?? 0.0);
    }

    /**
     * The pricing settings this breakdown was computed from.
     *
     * @return array<string, mixed>
     */
    public function settingsSnapshot(): array
    {
        $snapshot = $this->meta['settings_snapshot'] ?? [];

        return is_array($snapshot) ? $snapshot : [];
    }

    public function isBalanced(): bool
    {
        return array_sum($this->lines) === $this->total;
    }

    /**
     * The lines sum to the total, to the cent.
     */
    public function assertBalanced(): void
    {
        $sum = array_sum($this->lines);

        if ($sum !== $this->total) {
            throw new PricingException(sprintf(
                'Breakdown does not balance: lines sum to %d but the total is %d (%s).',
                $sum,
                $this->total,
                $this->stage() ?? 'no stage'
            ));
        }
    }

    /**
     * @return array{lines: array<string, int>, total: int, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'lines' => $this->lines,
            'total' => $this->total,
            'meta' => $this->meta,
        ];
    }

    /**
     * Key, label and cents per line, for receipts and the offer card.
     *
     * @return list<array{key: string, label: string, cents: int}>
     */
    public function displayLines(): array
    {
        $rows = [];

        foreach ($this->lines as $key => $cents) {
            $rows[] = [
                'key' => $key,
                'label' => self::LABELS[$key] ?? $key,
                'cents' => $cents,
            ];
        }

        return $rows;
    }

    /**
     * The row this breakdown writes to order_price_breakdown.
     *
     * @return array<string, mixed>
     */
    public function toRow(int $orderId, ?string $stage = null): array
    {
        $stage ??= $this->stage();

        if ($stage === null) {
            throw new PricingException('A breakdown row needs a stage.');
        }

        return [
            'order_id' => $orderId,
            'stage' => $stage,
            'subtotal_cents' => $this->line(self::SUBTOTAL),
            'tax_cents' => $this->line(self::TAX),
            'driver_base_cents' => (int) ($this->meta['driver_base_cents'] ?? 0),
            'driver_mileage_cents' => (int) ($this->meta['driver_mileage_cents'] ?? 0),
            'driver_guaranteed_cents' => $this->line(self::DRIVER_PAY),
            'wait_pay_cents' => $this->line(self::WAIT_PAY),
            'tip_cents' => $this->line(self::TIP),
            'platform_fee_cents' => $this->line(self::PLATFORM_FEE),
            'service_fee_cents' => $this->line(self::SERVICE_FEE),
            'total_cents' => $this->total,
            'settings_snapshot' => json_encode($this->settingsSnapshot(), JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Rebuilds a breakdown from a stored order_price_breakdown row.
     *
     * Receipts read the frozen row, never live settings, so this is how a
     * delivered order is shown months later.
     */
    public static function fromRow(array $row): self
    {
        $snapshot = OrderPriceBreakdown::settingsSnapshot($row);

        return new self(
            [
                self::SUBTOTAL => (int) ($row['subtotal_cents'] ?? 0),
                self::TAX => (int) ($row['tax_cents'] ?? 0),
                self::DRIVER_PAY => (int) ($row['driver_guaranteed_cents'] ?? 0),
                self::WAIT_PAY => (int) ($row['wait_pay_cents'] ?? 0),
                self::TIP => (int) ($row['tip_cents'] ?? 0),
                self::PLATFORM_FEE => (int) ($row['platform_fee_cents'] ?? 0),
                self::SERVICE_FEE => (int) ($row['service_fee_cents'] ?? 0),
            ],
            (int) ($row['total_cents'] ?? 0),
            [
                'stage' => $row['stage'] ?? null,
                'driver_base_cents' => (int) ($row['driver_base_cents'] ?? 0),
                'driver_mileage_cents' => (int) ($row['driver_mileage_cents'] ?? 0),
                'route_miles' => (float) ($snapshot['route_miles'] ?? 0.0),
                'is_member' => (bool) ($snapshot['is_member'] ?? false),
                'settings_snapshot' => $snapshot,
            ]
        );
    }
}
