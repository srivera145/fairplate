<?php

namespace Keel\App\Services\Delivery;

use Keel\App\Models\Driver;
use Keel\App\Models\DriverLocation;
use Keel\App\Models\Order;
use Keel\App\Models\Restaurant;
use Keel\App\Services\Dispatch\DispatchService;

/**
 * FairPlate's own drivers, behind the provider interface.
 *
 * Thin on purpose. DispatchService is where the rounds, the clocks and the
 * locking live; this is the adapter that lets the same code path ask for a
 * courier without caring which network answers. Everything here is a
 * translation between dispatch's vocabulary — offers, rounds, idle — and the
 * four words every provider reports in.
 */
final class InHouseDriverProvider implements DeliveryProvider
{
    public function __construct(private readonly DispatchService $dispatch = new DispatchService())
    {
    }

    public function key(): string
    {
        return 'in_house';
    }

    /**
     * Anything with a restaurant this dispatcher can measure from.
     *
     * That really is the only precondition. Offers are ranked by distance to the
     * pickup, so a restaurant with no position cannot be dispatched for at all —
     * whereas the customer's end was checked against the delivery zone at
     * checkout and paid for, and re-asking here would let a zone redrawn this
     * afternoon strand an order somebody has already been charged for.
     */
    public function supports(array $order): bool
    {
        $restaurant = Restaurant::find((int) $order['restaurant_id']);

        return $restaurant !== null
            && ($restaurant['lat'] ?? null) !== null
            && ($restaurant['lng'] ?? null) !== null;
    }

    /**
     * Starts dispatching. The answer is nearly always "searching": the first
     * round is queued rather than run here, so a kitchen tapping Accept is not
     * waiting on a driver search.
     */
    public function request(array $order): array
    {
        $orderId = (int) $order['id'];

        if (!$this->supports($order)) {
            return [
                'status' => self::STATUS_UNAVAILABLE,
                'reference' => null,
                'message' => 'That address is outside the delivery zone.',
            ];
        }

        $this->dispatch->start($orderId);

        return [
            'status' => self::STATUS_SEARCHING,
            'reference' => (string) $orderId,
            'message' => 'Looking for the nearest driver.',
        ];
    }

    /**
     * Closes any offer still on a driver's screen. A driver already assigned is
     * left alone: taking an order off somebody mid-run is the lifecycle's
     * decision to make, not a provider's.
     */
    public function cancel(array $order, string $reason = ''): void
    {
        $this->dispatch->stop((int) $order['id']);
    }

    public function status(array $order): array
    {
        $driverId = $order['driver_id'] ?? null;

        if ($driverId === null) {
            return [
                'status' => $this->searchingOrUnavailable($order),
                'courier_name' => null,
                'lat' => null,
                'lng' => null,
                'updated_at' => null,
            ];
        }

        $location = DriverLocation::latestForOrder((int) $order['id']);
        $driver = Driver::find((int) $driverId);
        $user = $driver === null ? null : Driver::user($driver);

        return [
            'status' => $this->assignedOrFinished($order),
            'courier_name' => $user === null ? null : $this->firstName((string) ($user['name'] ?? '')),
            'lat' => $location === null ? null : (float) $location['lat'],
            'lng' => $location === null ? null : (float) $location['lng'],
            'updated_at' => $location === null ? null : (string) $location['recorded_at'],
        ];
    }

    private function searchingOrUnavailable(array $order): string
    {
        return match ((string) $order['status']) {
            Order::STATUS_NEEDS_ATTENTION => self::STATUS_UNAVAILABLE,
            Order::STATUS_CANCELLED, Order::STATUS_REJECTED => self::STATUS_CANCELLED,
            default => self::STATUS_SEARCHING,
        };
    }

    private function assignedOrFinished(array $order): string
    {
        return match ((string) $order['status']) {
            Order::STATUS_DELIVERED => self::STATUS_DELIVERED,
            Order::STATUS_CANCELLED => self::STATUS_CANCELLED,
            default => self::STATUS_ASSIGNED,
        };
    }

    /**
     * A customer recognises a car and a first name. The rest of a driver's name
     * is not part of a delivery.
     */
    private function firstName(string $name): ?string
    {
        $name = trim($name);

        return $name === '' ? null : explode(' ', $name)[0];
    }
}
