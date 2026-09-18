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
     * A price typed in dollars, as integer cents.
     *
     * Menus are edited in dollars because that is what is printed on the board
     * behind the counter, and stored in cents because everything downstream is
     * integer arithmetic. This is the only place that conversion happens, in
     * either direction, so there is exactly one answer to what "12.5" means.
     *
     * The string is parsed digit by digit rather than multiplied by 100: (int)
     * (1.15 * 100) is 114 on every machine this will ever run on, and a penny
     * lost here is a penny the restaurant never sees.
     *
     * @param bool $allowNegative option price deltas may discount an item
     */
    public static function fromDollars(string $dollars, bool $allowNegative = false): int
    {
        $trimmed = trim(str_replace([',', '$', ' '], '', $dollars));

        if ($trimmed === '') {
            throw new PricingException('A price is required.');
        }

        if (!preg_match('/^(-?)(\d*)(?:\.(\d{0,2}))?$/', $trimmed, $matches)) {
            throw new PricingException("\"{$dollars}\" is not a price. Use dollars and cents, like 12.50.");
        }

        $whole = $matches[2];
        $fraction = $matches[3] ?? '';

        if ($whole === '' && $fraction === '') {
            throw new PricingException("\"{$dollars}\" is not a price. Use dollars and cents, like 12.50.");
        }

        $negative = $matches[1] === '-';

        if ($negative && !$allowNegative) {
            throw new PricingException('A price cannot be negative.');
        }

        $cents = ((int) ($whole === '' ? '0' : $whole)) * 100
            + (int) str_pad($fraction, 2, '0', STR_PAD_RIGHT);

        return $negative ? -$cents : $cents;
    }

    /**
     * Integer cents as the dollars string a form field should show.
     *
     * Built by hand rather than with number_format, which would route the value
     * through a float on the way back out.
     */
    public static function toDollars(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $magnitude = abs($cents);

        return $sign . intdiv($magnitude, 100) . '.' . str_pad((string) ($magnitude % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Integer cents as the string a screen shows: $12.50, -$1.00.
     *
     * Display, not arithmetic, but it belongs here for the same reason
     * toDollars() does: every receipt, breakdown and card in the application
     * formats money the same way, and the alternative is twenty views each
     * inventing their own and one of them getting it wrong.
     */
    public static function usd(int $cents): string
    {
        return ($cents < 0 ? '-$' : '$') . self::toDollars(abs($cents));
    }

    /**
     * A percentage typed by a human as the decimal rate a column stores.
     *
     * Sales tax is 7.5% on the sign behind the counter and 0.0750 in the
     * database, and the trip between the two is the same class of mistake as
     * dollars to cents: (float) "7.5" / 100 is not 0.075, and a tax rate that is
     * wrong in the fourth decimal is wrong on every order forever.
     *
     * Capped at two decimal places of percent, which is the precision
     * DECIMAL(5,4) can hold once divided by a hundred.
     */
    public static function rateFromPercent(string $percent): string
    {
        $trimmed = trim(str_replace(['%', ' '], '', $percent));

        if (!preg_match('/^(\d{1,2})(?:\.(\d{0,2}))?$/', $trimmed, $matches)) {
            throw new PricingException("\"{$percent}\" is not a percentage. Use a number like 7.5.");
        }

        // Hundredths of a percent, so 7.5% is 750 and the division by 100 that
        // turns a percentage into a rate is a shift of the decimal point.
        $hundredths = ((int) $matches[1] * 100)
            + (int) str_pad($matches[2] ?? '', 2, '0', STR_PAD_RIGHT);

        return '0.' . str_pad((string) $hundredths, 4, '0', STR_PAD_LEFT);
    }

    /**
     * A stored decimal rate as the percentage a form field should show, with no
     * trailing zeros: 0.0750 reads back as 7.5, not 7.500.
     */
    public static function percentFromRate(string $rate): string
    {
        [$numerator, $denominator] = self::ratio($rate);
        $hundredths = intdiv(10000 * $numerator, $denominator);
        $fraction = rtrim(str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT), '0');

        return intdiv($hundredths, 100) . ($fraction === '' ? '' : '.' . $fraction);
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
