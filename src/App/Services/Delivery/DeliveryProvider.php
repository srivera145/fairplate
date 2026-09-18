<?php

namespace Keel\App\Services\Delivery;

/**
 * Whoever carries the food.
 *
 * FairPlate's own drivers are the point of the product — independent, paid a
 * guarantee they can see before they accept, keeping every cent of the tip —
 * and InHouseDriverProvider is the implementation that matters. This interface
 * exists because a marketplace that cannot deliver an order has failed it, and
 * at four in the morning in a thunderstorm there may be nobody online. A
 * third-party courier network is the fallback, not the plan.
 *
 * The seam is drawn where the two genuinely differ. Both are asked to find
 * somebody, both can be called off, and both can be asked where things stand;
 * everything else — what the customer was charged, what the driver is
 * guaranteed, when the order changed status — belongs to PricingService and
 * OrderLifecycle whichever provider is carrying the bag, because a fallback
 * that priced orders differently would be a second set of books.
 *
 * Implementations must not throw for an order they simply cannot serve.
 * `supports()` answers that in advance and `request()` reports it in its return
 * value, so the caller can try the next provider rather than catch its way
 * through a list.
 */
interface DeliveryProvider
{
    /** Still looking for somebody to carry it. */
    public const STATUS_SEARCHING = 'searching';

    /** Somebody is carrying it. */
    public const STATUS_ASSIGNED = 'assigned';

    /** Nobody is, and this provider is not going to find anybody. */
    public const STATUS_UNAVAILABLE = 'unavailable';

    /** It arrived. */
    public const STATUS_DELIVERED = 'delivered';

    /** It was called off. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * A short stable identifier, for logs and for admin screens that have to say
     * which network a delivery went to.
     */
    public function key(): string;

    /**
     * Can this provider carry this order at all?
     *
     * Asked before request(), so a dispatcher walking a list of providers can
     * skip one that does not cover the address rather than spend a round
     * discovering it.
     */
    public function supports(array $order): bool;

    /**
     * Find somebody to carry this order.
     *
     * Returns where things stand the moment the request was made — searching for
     * a network that will take time, assigned for one that answered straight
     * away, unavailable for one that will not be taking it. `reference` is the
     * provider's own identifier for the job, where it has one, so a later
     * status() or cancel() has something to quote.
     *
     * @return array{status: string, reference: string|null, message: string}
     */
    public function request(array $order): array;

    /**
     * Call it off. Safe to call for an order this provider never took.
     */
    public function cancel(array $order, string $reason = ''): void;

    /**
     * Where the delivery stands, and where the courier is if the provider says.
     *
     * Position is nullable throughout: a courier who has not reported yet, and a
     * network that does not share positions at all, are both answered the same
     * way rather than with a coordinate nobody should trust.
     *
     * @return array{status: string, courier_name: string|null, lat: float|null, lng: float|null, updated_at: string|null}
     */
    public function status(array $order): array;
}
