<?php

namespace Keel\App\Services\Pricing;

/**
 * The integer arithmetic every price in FairPlate is built from.
 *
 * Rates arrive as exact decimal strings — "0.029" from settings, "0.0750" from
 * a restaurant's tax_rate — and are turned into an integer numerator over a
 * power-of-ten denominator. Nothing here ever casts a rate to float, because
 * 0.029 is not representable in binary and the error shows up as a cent that
 * appears or vanishes once a day.
 *
 * Two roundings, and only two, per the spec: tax and any other percentage of a
 * known amount round to nearest; the processing gross-up ceilings, so the
 * service fee always covers the real card cost rather than landing a cent short.
 */
final class Money
{
    /** Beyond this a power of ten stops being an exact integer to multiply by. */
    private const MAX_RATE_DECIMALS = 9;

    /**
     * An exact decimal string as numerator and denominator.
     *
     * "0.029" becomes [29, 1000]; "0.0750" becomes [750, 10000].
     *
     * @return array{0: int, 1: int}
     */
    public static function ratio(string $decimal): array
    {
        $trimmed = trim($decimal);

        if (!preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $trimmed, $matches)) {
            throw new PricingException("\"{$decimal}\" is not a decimal rate.");
        }

        $whole = $matches[2];
        $fraction = $matches[3] ?? '';

        if ($whole === '' && $fraction === '') {
            throw new PricingException("\"{$decimal}\" is not a decimal rate.");
        }

        if (strlen($fraction) > self::MAX_RATE_DECIMALS) {
            throw new PricingException("Rate \"{$decimal}\" carries more than " . self::MAX_RATE_DECIMALS . ' decimals.');
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $numerator = (int) (($whole === '' ? '0' : $whole) . $fraction);
        $denominator = 10 ** strlen($fraction);

        return [$sign * $numerator, $denominator];
    }

    /**
     * round(cents × rate) to the nearest cent, halves away from zero.
     *
     * This is the tax rule and the rule every special discount follows.
     */
    public static function percentOf(int $cents, string $rate): int
    {
        [$numerator, $denominator] = self::ratio($rate);

        if ($numerator === 0 || $cents === 0) {
            return 0;
        }

        $product = $cents * $numerator;
        $negative = $product < 0;
        $magnitude = $negative ? -$product : $product;

        // floor(x + 1/2) on the exact fraction magnitude/denominator.
        $rounded = intdiv(2 * $magnitude + $denominator, 2 * $denominator);

        return $negative ? -$rounded : $rounded;
    }

    /**
     * ceil((cents + fixed) / (1 − rate)): the card-processing gross-up.
     *
     * The ceiling is what makes the service fee exact in the direction that
     * matters. Rounding to nearest would, half the time, leave the processor's
     * cut a cent short and FairPlate paying the difference on an order — which
     * the spec forbids outright.
     */
    public static function grossUp(int $cents, int $fixedCents, string $rate): int
    {
        if ($cents < 0 || $fixedCents < 0) {
            throw new PricingException('A charge cannot be grossed up from a negative amount.');
        }

        [$numerator, $denominator] = self::ratio($rate);

        if ($numerator < 0) {
            throw new PricingException("Processing rate \"{$rate}\" cannot be negative.");
        }

        $remainder = $denominator - $numerator;

        if ($remainder <= 0) {
            throw new PricingException("Processing rate \"{$rate}\" must be below 1.");
        }

        $scaled = ($cents + $fixedCents) * $denominator;

        // ceil(a / b) for non-negative a.
        return intdiv($scaled + $remainder - 1, $remainder);
    }

    /**
     * Splits an amount across weights so the parts sum to the amount exactly.
     *
     * Largest remainder: everyone takes their floor share, then the cents left
     * over go to the lines that were cut hardest. An order-level discount split
     * any other way loses or invents a cent, and the breakdown stops balancing.
     *
     * @param list<int> $weights
     * @return list<int>
     */
    public static function allocate(int $amount, array $weights): array
    {
        $count = count($weights);

        if ($count === 0) {
            return [];
        }

        if ($amount < 0) {
            throw new PricingException('Only a non-negative amount can be allocated.');
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new PricingException('Allocation weights cannot be negative.');
            }
        }

        $totalWeight = array_sum($weights);

        if ($amount === 0 || $totalWeight <= 0) {
            return array_fill(0, $count, 0);
        }

        $shares = [];
        $remainders = [];
        $distributed = 0;

        foreach (array_values($weights) as $index => $weight) {
            $scaled = $amount * $weight;
            $share = intdiv($scaled, $totalWeight);

            $shares[$index] = $share;
            $remainders[$index] = $scaled - ($share * $totalWeight);
            $distributed += $share;
        }

        // PHP 8 sorts are stable, so equal remainders keep line order.
        arsort($remainders);

        $left = $amount - $distributed;

        foreach (array_keys($remainders) as $index) {
            if ($left <= 0) {
                break;
            }

            $shares[$index]++;
            $left--;
        }

        ksort($shares);

        return array_values($shares);
    }
}
