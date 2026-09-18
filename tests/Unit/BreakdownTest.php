<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Services\Pricing\Breakdown;
use Keel\App\Services\Pricing\PricingException;
use PHPUnit\Framework\TestCase;

/**
 * The charge-line value object on its own.
 *
 * Its whole job is to be checkable: a breakdown that does not balance must say
 * so loudly rather than travel on to a card, and the row it writes has to match
 * the order_price_breakdown columns exactly.
 */
class BreakdownTest extends TestCase
{
    public function testLinesComeBackInTheOrderACustomerReadsThem(): void
    {
        $breakdown = new Breakdown(
            [
                Breakdown::SERVICE_FEE => 146,
                Breakdown::SUBTOTAL => 2350,
                Breakdown::TIP => 500,
                Breakdown::TAX => 176,
                Breakdown::PLATFORM_FEE => 199,
                Breakdown::DRIVER_PAY => 600,
                Breakdown::WAIT_PAY => 0,
            ],
            3971
        );

        self::assertSame(Breakdown::LINE_ORDER, array_keys($breakdown->lines()));
    }

    public function testTotalIsCarriedNotRecomputed(): void
    {
        $breakdown = new Breakdown([Breakdown::SUBTOTAL => 1000], 1234);

        self::assertSame(1234, $breakdown->total());
        self::assertSame(1000, $breakdown->line(Breakdown::SUBTOTAL));
    }

    public function testAMissingLineReadsAsZeroWithoutPretendingItExists(): void
    {
        $breakdown = new Breakdown([Breakdown::SUBTOTAL => 1000], 1000);

        self::assertSame(0, $breakdown->line(Breakdown::WAIT_PAY));
        self::assertFalse($breakdown->has(Breakdown::WAIT_PAY));
        self::assertTrue($breakdown->has(Breakdown::SUBTOTAL));
    }

    public function testABalancedBreakdownPassesAssertBalanced(): void
    {
        $breakdown = new Breakdown(
            [Breakdown::SUBTOTAL => 1000, Breakdown::TAX => 75, Breakdown::SERVICE_FEE => 62],
            1137
        );

        $breakdown->assertBalanced();

        self::assertTrue($breakdown->isBalanced());
    }

    public function testAnUnbalancedBreakdownThrows(): void
    {
        $breakdown = new Breakdown([Breakdown::SUBTOTAL => 1000, Breakdown::TAX => 75], 1076);

        self::assertFalse($breakdown->isBalanced());

        $this->expectException(PricingException::class);
        $this->expectExceptionMessage('does not balance');

        $breakdown->assertBalanced();
    }

    public function testOneCentOutIsStillOut(): void
    {
        $breakdown = new Breakdown([Breakdown::SUBTOTAL => 1000], 1001);

        $this->expectException(PricingException::class);

        $breakdown->assertBalanced();
    }

    public function testAnUnknownLineIsRefused(): void
    {
        $this->expectException(PricingException::class);
        $this->expectExceptionMessage('Unknown breakdown line(s): surcharge.');

        new Breakdown([Breakdown::SUBTOTAL => 1000, 'surcharge' => 50], 1050);
    }

    public function testANonIntegerLineIsRefused(): void
    {
        $this->expectException(PricingException::class);
        $this->expectExceptionMessage('must be integer cents');

        new Breakdown([Breakdown::SUBTOTAL => 10.5], 1050);
    }

    public function testTheServiceFeeIsNeverLabelledSomethingElse(): void
    {
        self::assertSame('Service fee', Breakdown::LABELS[Breakdown::SERVICE_FEE]);

        $labels = implode(' ', Breakdown::LABELS);

        self::assertStringNotContainsStringIgnoringCase('surcharge', $labels);
        self::assertStringNotContainsStringIgnoringCase('card fee', $labels);
    }

    public function testDisplayLinesCarryTheirLabels(): void
    {
        $breakdown = new Breakdown([Breakdown::SUBTOTAL => 1000, Breakdown::SERVICE_FEE => 62], 1062);

        self::assertSame(
            [
                ['key' => 'subtotal', 'label' => 'Food subtotal', 'cents' => 1000],
                ['key' => 'service_fee', 'label' => 'Service fee', 'cents' => 62],
            ],
            $breakdown->displayLines()
        );
    }

    public function testToArrayCarriesLinesTotalAndMeta(): void
    {
        $breakdown = new Breakdown(
            [Breakdown::SUBTOTAL => 1000],
            1000,
            ['stage' => OrderPriceBreakdown::STAGE_ESTIMATE, 'route_miles' => 3.0]
        );

        self::assertSame(
            [
                'lines' => ['subtotal' => 1000],
                'total' => 1000,
                'meta' => ['stage' => 'estimate', 'route_miles' => 3.0],
            ],
            $breakdown->toArray()
        );
    }

    public function testToRowMatchesTheOrderPriceBreakdownColumns(): void
    {
        $breakdown = $this->workedBreakdown();

        $row = $breakdown->toRow(42);

        self::assertSame(
            [
                'order_id' => 42,
                'stage' => 'authorized',
                'subtotal_cents' => 2350,
                'tax_cents' => 176,
                'driver_base_cents' => 300,
                'driver_mileage_cents' => 300,
                'driver_guaranteed_cents' => 600,
                'wait_pay_cents' => 300,
                'tip_cents' => 500,
                'platform_fee_cents' => 199,
                'service_fee_cents' => 155,
                'total_cents' => 4280,
                'settings_snapshot' => '{"processing_pct":"0.029","route_miles":3}',
            ],
            $row
        );
    }

    public function testToRowRefusesWithoutAStage(): void
    {
        $this->expectException(PricingException::class);
        $this->expectExceptionMessage('needs a stage');

        (new Breakdown([Breakdown::SUBTOTAL => 1000], 1000))->toRow(42);
    }

    public function testARowRoundTripsBackIntoABalancedBreakdown(): void
    {
        $original = $this->workedBreakdown();

        $restored = Breakdown::fromRow($original->toRow(42));

        $restored->assertBalanced();

        self::assertSame($original->lines(), $restored->lines());
        self::assertSame($original->total(), $restored->total());
        self::assertSame(300, $restored->metaValue('driver_base_cents'));
        self::assertSame(3.0, $restored->routeMiles());
        self::assertSame(['processing_pct' => '0.029', 'route_miles' => 3], $restored->settingsSnapshot());
    }

    private function workedBreakdown(): Breakdown
    {
        return new Breakdown(
            [
                Breakdown::SUBTOTAL => 2350,
                Breakdown::TAX => 176,
                Breakdown::DRIVER_PAY => 600,
                Breakdown::WAIT_PAY => 300,
                Breakdown::TIP => 500,
                Breakdown::PLATFORM_FEE => 199,
                Breakdown::SERVICE_FEE => 155,
            ],
            4280,
            [
                'stage' => OrderPriceBreakdown::STAGE_AUTHORIZED,
                'driver_base_cents' => 300,
                'driver_mileage_cents' => 300,
                'route_miles' => 3.0,
                'settings_snapshot' => ['processing_pct' => '0.029', 'route_miles' => 3],
            ]
        );
    }
}
