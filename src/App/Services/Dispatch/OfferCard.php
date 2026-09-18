<?php

namespace Keel\App\Services\Dispatch;

use Keel\App\Models\DispatchOffer;
use Keel\App\Models\Order;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Services\Pricing\PricingException;
use Keel\App\Services\Pricing\PricingService;

/**
 * Everything on one offer card, assembled once.
 *
 * Three screens show this card — the home screen when an offer arrives with the
 * page, the three-second poll that hands back the same markup, and the
 * standalone offer URL — and a driver deciding in forty-five seconds must not
 * be shown three slightly different numbers. So there is one assembler, and the
 * views read its keys.
 *
 * Where each number comes from is the whole point of this class:
 *
 *   The payout, the base, the mileage and the rate per mile come from
 *   PricingService, computed from the breakdown frozen on the order at
 *   checkout. Not from live settings, and not from a fresh measurement. The
 *   spec says a guarantee never drops after acceptance, and the only way to
 *   keep that is for the card to be arithmetic on what the customer was already
 *   charged.
 *
 *   The tip comes off the same frozen row. Tips go whole to the driver, so it
 *   is part of the guarantee the card leads with rather than a footnote.
 *
 *   The drop-off distance is the order's route_miles, locked at checkout,
 *   because that is the number the mileage pay was computed from.
 *
 *   The distance to the restaurant is the only thing measured now, from the
 *   driver's last ping. It is a straight-line estimate and it pays nothing; it
 *   is there so a driver can tell "round the corner" from "across town".
 */
final class OfferCard
{
    /**
     * @param array<string, mixed> $driver
     * @param array<string, mixed> $offer
     * @return array<string, mixed>|null null when the order or its pricing has
     *         gone, which is a race rather than an error — the caller shows
     *         nothing and the next poll agrees
     */
    public static function forOffer(array $driver, array $offer): ?array
    {
        $orderId = (int) $offer['order_id'];
        $order = Order::withRestaurant($orderId);

        if ($order === null) {
            return null;
        }

        $row = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_AUTHORIZED)
            ?? OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_ESTIMATE);

        if ($row === null) {
            error_log('[FairPlate] Order ' . $orderId . ' was offered with no priced breakdown.');

            return null;
        }

        try {
            $pay = (new PricingService())->driverOffer($row);
        } catch (PricingException $exception) {
            error_log('[FairPlate] Offer card for order ' . $orderId . ': ' . $exception->getMessage());

            return null;
        }

        return [
            'offer' => $offer,
            'order' => $order,
            'restaurant_name' => (string) $order['restaurant_name'],
            'pay' => $pay,
            'dropoff_miles' => (float) $order['route_miles'],
            'to_restaurant_miles' => self::milesToRestaurant($driver, $order),
            'seconds_left' => DispatchOffer::secondsLeft($offer),
            'window_seconds' => self::window($offer),
        ];
    }

    /**
     * How far the driver is from the pickup, or null when we have no position
     * recent enough to say.
     *
     * Null rather than a guess: a card that says "0.0 mi to the restaurant"
     * because a phone had not reported yet is worse than one that leaves the
     * line out, since the distance is half of what a driver is deciding on.
     */
    private static function milesToRestaurant(array $driver, array $order): ?float
    {
        $lat = $driver['last_lat'] ?? null;
        $lng = $driver['last_lng'] ?? null;

        if ($lat === null || $lng === null
            || ($order['restaurant_lat'] ?? null) === null
            || ($order['restaurant_lng'] ?? null) === null) {
            return null;
        }

        return (new DispatchService())->milesBetween(
            (float) $lat,
            (float) $lng,
            (float) $order['restaurant_lat'],
            (float) $order['restaurant_lng']
        );
    }

    /**
     * The full length of the countdown, so the ring can be drawn as a fraction
     * rather than as a guess at what the timeout setting was when this offer was
     * made.
     */
    private static function window(array $offer): int
    {
        $from = strtotime((string) ($offer['offered_at'] ?? '') . ' UTC');
        $to = strtotime((string) ($offer['expires_at'] ?? '') . ' UTC');

        if ($from === false || $to === false || $to <= $from) {
            return 1;
        }

        return $to - $from;
    }
}
