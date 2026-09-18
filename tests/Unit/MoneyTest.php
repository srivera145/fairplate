<?php

declare(strict_types=1);

namespace Tests\Unit;

use Keel\App\Services\Pricing\Money;
use Keel\App\Services\Pricing\PricingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The integer arithmetic underneath every price.
 *
 * These are the properties the rest of the pricing suite leans on: rates stay
 * exact, tax rounds to nearest, the gross-up ceilings, and a split never loses
 * or invents a cent.
 */
class MoneyTest extends TestCase
{
    #[DataProvider('rates')]
    public function testARateBecomesAnExactFraction(string $rate, int $numerator, int $denominator): void
    {
        self::assertSame([$numerator, $denominator], Money::ratio($rate));
    }

    public static function rates(): array
    {
        return [
            'processing_pct' => ['0.029', 29, 1000],
            'a tax rate as MySQL returns it' => ['0.0750', 750, 10000],
            'a quarter' => ['0.25', 25, 100],
            'no decimals at all' => ['0', 0, 1],
            'a leading dot' => ['.5', 5, 10],
            'whitespace around it' => [' 0.029 ', 29, 1000],
        ];
    }

    #[DataProvider('nonRates')]
    public function testSomethingThatIsNotARateIsRefused(string $input): void
    {
        $this->expectException(PricingException::class);

        Money::ratio($input);
    }

    public static function nonRates(): array
    {
        return [
            'empty' => [''],
            'a word' => ['two point nine percent'],
            'a percent sign' => ['2.9%'],
            'scientific notation' => ['2.9e-2'],
            'absurd precision' => ['0.0000000001'],
        ];
    }

    public function testPercentOfRoundsToTheNearestCent(): void
    {
        // 1234 × 0.0750 = 92.55
        self::assertSame(93, Money::percentOf(1234, '0.0750'));
        // 2350 × 0.0750 = 176.25
        self::assertSame(176, Money::percentOf(2350, '0.0750'));
        // Exactly 750, no rounding involved.
        self::assertSame(750, Money::percentOf(10000, '0.0750'));
    }

    public function testPercentOfRoundsAHalfUp(): void
    {
        // 1000 × 0.0005 = 0.5
        self::assertSame(1, Money::percentOf(1000, '0.0005'));
    }

    public function testPercentOfAZeroRateOrAZeroAmountIsZero(): void
    {
        self::assertSame(0, Money::percentOf(9999, '0.0000'));
        self::assertSame(0, Money::percentOf(0, '0.0750'));
    }

    public function testPercentOfRoundsANegativeAmountAwayFromZero(): void
    {
        self::assertSame(-93, Money::percentOf(-1234, '0.0750'));
    }

    public function testGrossUpCeilings(): void
    {
        // (1000 + 30) / 0.971 = 1060.76...
        self::assertSame(1061, Money::grossUp(1000, 30, '0.029'));
        // (3825 + 30) / 0.971 = 3970.13...
        self::assertSame(3971, Money::grossUp(3825, 30, '0.029'));
    }

    public function testGrossUpWithNoRateIsJustTheFixedFee(): void
    {
        self::assertSame(1030, Money::grossUp(1000, 30, '0'));
    }

    /**
     * The property the whole fee rests on, checked across the range.
     *
     * Stated in integers rather than floats: 0.035 × 28000 + 30 is 1010, but as
     * a float it is 1010.0000000000001, and a test that cannot state the rule
     * exactly is in no position to check it.
     */
    public function testTheGrossedUpTotalAlwaysCoversTheProcessorWithinACent(): void
    {
        $fixed = 30;

        foreach (['0.029', '0.026', '0.035'] as $rate) {
            [$numerator, $denominator] = Money::ratio($rate);

            for ($chargeable = 1; $chargeable <= 50000; $chargeable += 137) {
                $total = Money::grossUp($chargeable, $fixed, $rate);
                $fee = $total - $chargeable;

                // Both sides scaled by the rate's denominator.
                $required = ($numerator * $total) + ($fixed * $denominator);
                $charged = $fee * $denominator;

                self::assertGreaterThanOrEqual(
                    $required,
                    $charged,
                    "{$rate} at {$chargeable}: the fee must cover the processor"
                );
                self::assertLessThanOrEqual(
                    $denominator,
                    $charged - $required,
                    "{$rate} at {$chargeable}: the fee must not overshoot by more than a cent"
                );
            }
        }
    }

    public function testGrossUpRefusesARateOfOneOrMore(): void
    {
        $this->expectException(PricingException::class);
        $this->expectExceptionMessage('must be below 1');

        Money::grossUp(1000, 30, '1.0');
    }

    public function testGrossUpRefusesANegativeAmount(): void
    {
        $this->expectException(PricingException::class);

        Money::grossUp(-1, 30, '0.029');
    }

    public function testAllocateSplitsExactlyWithNoCentLostOrInvented(): void
    {
        $shares = Money::allocate(100, [333, 333, 333]);

        self::assertSame(100, array_sum($shares));
        self::assertSame([34, 33, 33], $shares);
    }

    public function testAllocateGivesTheOddCentsToTheLargestRemainders(): void
    {
        $shares = Money::allocate(10, [1, 1, 1]);

        self::assertSame([4, 3, 3], $shares);
        self::assertSame(10, array_sum($shares));
    }

    public function testAllocateIsProportional(): void
    {
        $shares = Money::allocate(300, [1000, 2000]);

        self::assertSame([100, 200], $shares);
    }

    public function testAllocateHandlesNothingToSplit(): void
    {
        self::assertSame([0, 0], Money::allocate(0, [500, 500]));
        self::assertSame([0, 0], Money::allocate(50, [0, 0]));
        self::assertSame([], Money::allocate(50, []));
    }

    public function testAllocateNeverLosesACentAcrossAwkwardSplits(): void
    {
        mt_srand(99);

        for ($i = 0; $i < 200; $i++) {
            $weights = [];
            $lines = mt_rand(1, 6);

            for ($line = 0; $line < $lines; $line++) {
                $weights[] = mt_rand(0, 5000);
            }

            $amount = mt_rand(0, (int) array_sum($weights));
            $shares = Money::allocate($amount, $weights);

            self::assertSame($amount, array_sum($shares));
            self::assertCount($lines, $shares);
        }
    }

    public function testAllocateRefusesNegatives(): void
    {
        $this->expectException(PricingException::class);

        Money::allocate(-5, [100, 100]);
    }
}
