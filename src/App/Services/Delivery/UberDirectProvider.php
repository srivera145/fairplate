<?php

namespace Keel\App\Services\Delivery;

/**
 * Uber Direct, as a shape rather than an implementation.
 *
 * Nothing here calls anything. It is checked in now so that the seam is real
 * while InHouseDriverProvider is the only thing behind it: an interface with one
 * implementation is a guess about what varies, and an interface with a second
 * file that had to be written against it is a tested one. Writing these four
 * signatures is what confirmed that request() has to be able to say
 * "unavailable" without throwing, and that status() has to allow a courier
 * position to be absent — both of which are Uber's behaviour, not ours.
 *
 * It exists for one scenario: an order nobody in-house takes, at an hour when
 * nobody is online. The spec's rules do not bend for it. The customer is
 * charged what PricingService quoted, the restaurant keeps subtotal and tax in
 * full, and the courier fee comes out of FairPlate rather than out of the
 * kitchen — a third-party courier is more expensive than a FairPlate driver,
 * and "no commission, nothing deducted" has no asterisk for a bad night.
 *
 * TODO (phase 7): implement against the Uber Direct API.
 *   - OAuth client-credentials token, cached until it expires.
 *   - POST /v1/customers/{id}/deliveries, built from the order's address
 *     snapshot and the restaurant's address. Quote first, and pass the tip
 *     through as tip rather than as fee: the spec gives tips whole to whoever
 *     carries the food, and that stays true when the carrier is not ours.
 *   - Webhooks for status changes, verified and recorded in webhook_events the
 *     way the Stripe ones are, then replayed through OrderLifecycle so the
 *     customer's tracking page has one description of a step.
 *   - Courier position from the tracking payload, written to driver_locations
 *     with a null driver_id so the customer map needs no special case.
 *   - Cancellation windows: Uber charges for a delivery cancelled after pickup,
 *     which is a refund rule to settle before this ships, not after.
 *   - UBER_DIRECT_* credentials in .env, secrets nowhere else.
 */
final class UberDirectProvider implements DeliveryProvider
{
    public function key(): string
    {
        return 'uber_direct';
    }

    public function supports(array $order): bool
    {
        // TODO: quote the address pair against Uber's coverage.
        return false;
    }

    /**
     * @return array{status: string, reference: string|null, message: string}
     */
    public function request(array $order): array
    {
        // TODO: create the delivery and return its Uber id as the reference.
        return [
            'status' => self::STATUS_UNAVAILABLE,
            'reference' => null,
            'message' => 'Uber Direct is not connected yet.',
        ];
    }

    public function cancel(array $order, string $reason = ''): void
    {
        // TODO: POST the cancellation, and record what it cost when it is after
        // pickup.
    }

    /**
     * @return array{status: string, courier_name: string|null, lat: float|null, lng: float|null, updated_at: string|null}
     */
    public function status(array $order): array
    {
        // TODO: read the delivery, map Uber's statuses onto this interface's.
        return [
            'status' => self::STATUS_UNAVAILABLE,
            'courier_name' => null,
            'lat' => null,
            'lng' => null,
            'updated_at' => null,
        ];
    }
}
