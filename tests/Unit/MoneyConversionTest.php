<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Services\Pricing\Money;
use Keel\App\Services\Pricing\PricingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two conversions between what a human types and what the database holds.
 *
 * Both are one-liners that look too simple to test, and both are exactly where
 * a float would quietly cost a restaurant a penny per order. The cases below
 * are the ones that go wrong when the implementation multiplies by 100.
 */
class MoneyConversionTest extends TestCase
{
    #[DataProvider('dollarCases')]
    public function testDollarsBecomeCents(string $typed, int $expected): void
    {
        self::assertSame($expected, Money::fromDollars($typed));
    }

    public static function dollarCases(): array
    {
        return [
            'whole dollars' => ['12', 1200],
            'dollars and cents' => ['12.50', 1250],
            'one decimal' => ['12.5', 1250],
            'zero' => ['0', 0],
            'under a dollar' => ['0.99', 99],
            'leading dot' => ['.75', 75],
            'a dollar sign and a comma' => ['$1,234.56', 123456],
            // (int) (1.15 * 100) is 114. This is the case that decides whether
            // the implementation parses or multiplies.
            'the float trap' => ['1.15', 115],
            'the other float trap' => ['8.35', 835],
            'trailing dot' => ['9.', 900],
        ];
    }

    public function testNegativePricesAreRefusedUnlessTheCallerAsks(): void
    {
        $this->expectException(PricingException::class);

        Money::fromDollars('-1.00');
    }

    public function testAnOptionMayDiscount(): void
    {
        self::assertSame(-150, Money::fromDollars('-1.50', true));
    }

    #[DataProvider('rejectedPrices')]
    public function testNonsenseIsRefused(string $typed): void
    {
        $this->expectException(PricingException::class);

        Money::fromDollars($typed);
    }

    public static function rejectedPrices(): array
    {
        return [
            'empty' => [''],
            'words' => ['free'],
            'three decimals' => ['1.005'],
            'two dots' => ['1.2.3'],
            'just a dot' => ['.'],
        ];
    }

    public function testCentsComeBackAsDollars(): void
    {
        self::assertSame('12.50', Money::toDollars(1250));
        self::assertSame('0.09', Money::toDollars(9));
        self::assertSame('0.00', Money::toDollars(0));
        self::assertSame('100.00', Money::toDollars(10000));
        self::assertSame('-1.50', Money::toDollars(-150));
    }

    public function testTheRoundTripIsLossless(): void
    {
        foreach ([0, 1, 9, 99, 100, 115, 835, 1250, 999999] as $cents) {
            self::assertSame($cents, Money::fromDollars(Money::toDollars($cents)), "round trip of {$cents}");
        }
    }

    #[DataProvider('percentCases')]
    public function testPercentagesBecomeRates(string $typed, string $expected): void
    {
        self::assertSame($expected, Money::rateFromPercent($typed));
    }

    public static function percentCases(): array
    {
        return [
            'tallahassee sales tax' => ['7.5', '0.0750'],
            'whole percent' => ['8', '0.0800'],
            'two decimals' => ['8.25', '0.0825'],
            'zero' => ['0', '0.0000'],
            'a percent sign' => ['7.5%', '0.0750'],
            'a twenty percent special' => ['20', '0.2000'],
        ];
    }

    public function testRatesComeBackAsPercentages(): void
    {
        self::assertSame('7.5', Money::percentFromRate('0.0750'));
        self::assertSame('8', Money::percentFromRate('0.0800'));
        self::assertSame('8.25', Money::percentFromRate('0.0825'));
        self::assertSame('0', Money::percentFromRate('0.0000'));
        self::assertSame('20', Money::percentFromRate('0.2000'));
    }

    public function testTheTaxRateRoundTripIsLossless(): void
    {
        foreach (['0.0000', '0.0750', '0.0825', '0.0600', '0.2000'] as $rate) {
            self::assertSame(
                $rate,
                Money::rateFromPercent(Money::percentFromRate($rate)),
                "round trip of {$rate}"
            );
        }
    }

    /**
     * The rate that comes out has to be one PricingService can still price
     * against, which is the whole reason it is a string.
     */
    public function testTheRateIsUsableByThePricingMath(): void
    {
        self::assertSame(150, Money::percentOf(2000, Money::rateFromPercent('7.5')));
    }
}
