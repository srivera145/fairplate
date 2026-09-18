<?php

namespace Keel\App\Services\Dispatch;

use Keel\App\Jobs\DispatchOfferJob;
use Keel\App\Jobs\OfferTimeoutJob;
use Keel\App\Models\DispatchOffer;
use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Models\Restaurant;
use Keel\App\Services\OrderLifecycle;
use Keel\App\Services\OrderLifecycleException;
use Keel\App\Services\Pricing\PricingException;
use Keel\App\Services\Pricing\PricingService;
use Keel\App\Services\Settings;
use Keel\App\Services\ZoneService;
use Keel\Core\Activity;
use Keel\Core\Database;
use Keel\Core\Queue;
use PDO;

/**
 * Finding a driver for an order, one at a time.
 *
 * The whole engine is a loop with three exits. A round offers the order to the
 * nearest driver who can take it and starts a clock. The clock ends in an
 * acceptance, a decline, or a timeout; the first finishes dispatch and the
 * other two start the next round. When the rounds or the minutes run out the
 * order stops being dispatch's problem and becomes a person's.
 *
 * Three rules are worth stating outright, because every race this code has to
 * survive is a violation of one of them:
 *
 *   One pending offer per order. Two drivers holding cards for the same food is
 *   a fight one of them loses after driving there.
 *
 *   One pending offer per driver. A driver choosing between two cards is a
 *   driver whose forty-five seconds run out on both.
 *
 *   One acceptance, ever. Accept takes a row lock on the order and re-reads it
 *   inside the lock, so a second tap finds a driver already assigned and is
 *   told so. It does not overwrite, and it does not throw a stack trace at
 *   somebody standing in a car park.
 *
 * Nearest is a great-circle distance to the restaurant, not a route. Routing
 * every online driver on every round would be a few dozen billable calls to
 * rank a list the answer almost never reorders — the driver two streets away is
 * the closest one however the roads run — and the numbers that are promises,
 * the guarantee and the drop-off distance, come from the order's frozen
 * snapshot rather than from anything measured here.
 */
final class DispatchService
{
    /**
     * How long to wait before looking again when nobody is eligible.
     *
     * Not a round: no offer was made, so nothing was used up. It exists because
     * "nobody is online right now" and "nobody will ever be online" are the same
     * state five seconds in, and only the dispatch_max_minutes clock can tell
     * them apart.
     */
    public const RETRY_SECONDS = 15;

    private const METERS_PER_MILE = 1609.344;

    /**
     * Hands an order to the dispatcher. Called the moment a kitchen accepts.
     *
     * Queued rather than run inline, so the tap on the kitchen tablet returns at
     * the speed of one UPDATE and a dispatch that cannot find anybody does not
     * look to the kitchen like an Accept that failed.
     */
    public function start(int $orderId): void
    {
        Queue::push(DispatchOfferJob::class, ['order_id' => $orderId]);
    }

    /**
     * Runs one round: offer the order to the nearest eligible driver.
     *
     * Returns the offer it created, or null when there was nothing to do —
     * because the order already has a driver, because one is already deciding,
     * because nobody is eligible yet, or because the order has just been handed
     * to a person instead.
     */
    public function dispatch(int $orderId): ?array
    {
        // A pending offer whose clock has run out is a timeout job that never
        // ran. Closing it here means an order advances as soon as anything looks
        // at it, rather than waiting on a worker that may be down.
        DispatchOffer::expireStaleForOrder($orderId);

        return $this->transactional(function (PDO $connection) use ($orderId): ?array {
            $order = $this->lockRow($connection, 'orders', $orderId);

            if ($order === null || !$this->awaitingDriver($order)) {
                return null;
            }

            if (DispatchOffer::pendingForOrder($orderId) !== null) {
                return null;
            }

            $round = DispatchOffer::latestRound($orderId) + 1;

            if ($round > $this->maxRounds()) {
                $this->escalate($order, 'rounds', $round - 1);

                return null;
            }

            if ($this->outOfTime($order)) {
                $this->escalate($order, 'minutes', $round - 1);

                return null;
            }

            $driver = $this->nearestCandidate($order);

            if ($driver === null) {
                Queue::push(
                    DispatchOfferJob::class,
                    ['order_id' => $orderId],
                    'default',
                    self::RETRY_SECONDS
                );

                return null;
            }

            return $this->makeOffer($orderId, (int) $driver['id'], $round);
        });
    }

    /**
     * A driver takes the order.
     *
     * The only method here that has to be exactly right under concurrency, so
     * everything it decides is decided inside a transaction holding row locks on
     * the offer, the order and the driver, and every check is made against what
     * those locked rows say rather than against what the screen said.
     *
     * @return array the order as it now stands
     * @throws DispatchException when somebody else got there first
     */
    public function accept(int $offerId, int $driverId): array
    {
        return $this->transactional(function (PDO $connection) use ($offerId, $driverId): array {
            $offer = $this->lockRow($connection, 'dispatch_offers', $offerId);

            if ($offer === null || (int) $offer['driver_id'] !== $driverId) {
                throw DispatchException::notYours();
            }

            if ((string) $offer['response'] !== DispatchOffer::RESPONSE_PENDING) {
                throw $this->whyClosed($offer);
            }

            if (DispatchOffer::secondsLeft($offer) <= 0) {
                $this->close($offerId, DispatchOffer::RESPONSE_EXPIRED);

                throw DispatchException::expired();
            }

            $orderId = (int) $offer['order_id'];
            $order = $this->lockRow($connection, 'orders', $orderId);

            if ($order === null) {
                throw DispatchException::gone();
            }

            // The whole race, in one condition. A second accept arriving a
            // millisecond later waits on this lock, re-reads the row, finds a
            // driver on it and is told no.
            if (!$this->awaitingDriver($order)) {
                $this->close($offerId, DispatchOffer::RESPONSE_EXPIRED);

                throw DispatchException::taken();
            }

            $driver = $this->lockRow($connection, 'drivers', $driverId);

            if (!Driver::isApproved($driver)) {
                throw DispatchException::unavailable('Your account is not approved to take orders yet.');
            }

            if ((int) ($driver['idle'] ?? 0) !== 1) {
                throw DispatchException::unavailable('Finish the delivery you are on first.');
            }

            $this->close($offerId, DispatchOffer::RESPONSE_ACCEPTED);
            DispatchOffer::expireOthersForOrder($orderId, $offerId);

            // driver_id and the status move in one statement, so there is no
            // instant in which an order is driver_assigned to nobody.
            $assigned = OrderLifecycle::transition($orderId, Order::STATUS_DRIVER_ASSIGNED, [
                'driver_id' => $driverId,
            ]);

            Driver::update($driverId, ['idle' => 0]);

            Activity::log('dispatch.accepted', 'Order', $orderId, [
                'driver_id' => $driverId,
                'offer_id' => $offerId,
                'round' => (int) $offer['round'],
            ]);

            return $assigned;
        });
    }

    /**
     * A driver turns the order down. The next round starts immediately.
     *
     * The next round runs after the decline has committed, not inside it: it has
     * to see the declined row to know not to offer the same driver again.
     */
    public function decline(int $offerId, int $driverId): void
    {
        $orderId = $this->transactional(function (PDO $connection) use ($offerId, $driverId): int {
            $offer = $this->lockRow($connection, 'dispatch_offers', $offerId);

            if ($offer === null || (int) $offer['driver_id'] !== $driverId) {
                throw DispatchException::notYours();
            }

            if ((string) $offer['response'] !== DispatchOffer::RESPONSE_PENDING) {
                throw $this->whyClosed($offer);
            }

            $this->close($offerId, DispatchOffer::RESPONSE_DECLINED);

            Activity::log('dispatch.declined', 'Order', (int) $offer['order_id'], [
                'driver_id' => $driverId,
                'offer_id' => $offerId,
                'round' => (int) $offer['round'],
            ]);

            return (int) $offer['order_id'];
        });

        $this->dispatch($orderId);
    }

    /**
     * Nobody answered. Closes the offer and starts the next round.
     *
     * A job that fires early — the queue has a delay, not a guarantee — puts
     * itself back rather than expiring an offer the driver can still see
     * counting down.
     */
    public function timeout(int $offerId): void
    {
        $offer = DispatchOffer::find($offerId);

        if ($offer === null || (string) $offer['response'] !== DispatchOffer::RESPONSE_PENDING) {
            return;
        }

        $secondsLeft = DispatchOffer::secondsLeft($offer);

        if ($secondsLeft > 0) {
            Queue::push(OfferTimeoutJob::class, ['offer_id' => $offerId], 'default', $secondsLeft);

            return;
        }

        $this->close($offerId, DispatchOffer::RESPONSE_EXPIRED);

        Activity::log('dispatch.expired', 'Order', (int) $offer['order_id'], [
            'driver_id' => (int) $offer['driver_id'],
            'offer_id' => $offerId,
            'round' => (int) $offer['round'],
        ]);

        $this->dispatch((int) $offer['order_id']);
    }

    /**
     * Stops dispatching an order: closes whatever is still open on it.
     *
     * For an order that left the flow while a card was on somebody's screen — a
     * kitchen rejection, a cancellation, an escalation. The driver's next poll
     * finds nothing and the card comes down.
     */
    public function stop(int $orderId): int
    {
        return DispatchOffer::expireOthersForOrder($orderId, 0);
    }

    /**
     * The eligible driver closest to the restaurant, or null when there is none.
     *
     * Public because it is the part worth being able to ask about directly: an
     * admin screen explaining why an order is still waiting asks this, rather
     * than reading the dispatch log backwards.
     */
    public function nearestCandidate(array $order): ?array
    {
        $restaurant = Restaurant::find((int) $order['restaurant_id']);

        if ($restaurant === null
            || ($restaurant['lat'] ?? null) === null
            || ($restaurant['lng'] ?? null) === null) {
            error_log('[FairPlate] Order ' . (int) $order['id'] . ' has no restaurant position to dispatch from.');

            return null;
        }

        return $this->nearest(
            Driver::candidatesForOrder((int) $order['id']),
            (float) $restaurant['lat'],
            (float) $restaurant['lng']
        );
    }

    /**
     * @param list<array<string, mixed>> $drivers
     * @return array<string, mixed>|null
     */
    public function nearest(array $drivers, float $lat, float $lng): ?array
    {
        $ranked = [];

        foreach ($drivers as $driver) {
            $ranked[] = [
                'driver' => $driver,
                'meters' => ZoneService::haversineMeters(
                    (float) $driver['last_lat'],
                    (float) $driver['last_lng'],
                    $lat,
                    $lng
                ),
            ];
        }

        if ($ranked === []) {
            return null;
        }

        // PHP 8 sorts are stable, so two drivers the same distance out keep the
        // order the query returned them in, which is oldest driver first.
        usort($ranked, static fn (array $a, array $b): int => $a['meters'] <=> $b['meters']);

        return $ranked[0]['driver'];
    }

    /**
     * Great-circle miles between two points, on the hundredths grid route miles
     * are stored at.
     *
     * This is the "distance to the restaurant" on an offer card. It is
     * deliberately not a route: it is a number a driver glances at to decide
     * whether the pickup is round the corner, and the number that has to be
     * exact — the drop-off distance the pay is computed from — is the routed one
     * frozen on the order.
     */
    public function milesBetween(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $meters = ZoneService::haversineMeters($fromLat, $fromLng, $toLat, $toLng);

        return round($meters / self::METERS_PER_MILE, 2);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed> the offer row
     */
    private function makeOffer(int $orderId, int $driverId, int $round): array
    {
        $timeout = $this->offerTimeoutSeconds();
        $now = time();

        $offerId = DispatchOffer::create([
            'order_id' => $orderId,
            'driver_id' => $driverId,
            'round' => $round,
            // What the card promises, written down at the moment it is
            // promised. The spec says the guarantee never drops after
            // acceptance, and PayoutService checks the transfer against this
            // rather than recomputing the number and agreeing with itself.
            'guaranteed_cents' => $this->guaranteedCents($orderId),
            'offered_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + $timeout),
        ]);

        Queue::push(OfferTimeoutJob::class, ['offer_id' => $offerId], 'default', $timeout);

        Activity::log('dispatch.offered', 'Order', $orderId, [
            'driver_id' => $driverId,
            'offer_id' => $offerId,
            'round' => $round,
            'expires_in' => $timeout,
        ]);

        return DispatchOffer::find($offerId) ?? [];
    }

    /**
     * The guarantee this offer card shows, from the order's frozen breakdown.
     *
     * Null rather than a guess when the breakdown has gone: the card itself
     * refuses to render in that case, so there was never a promise to record,
     * and a zero here would read like one that was kept.
     */
    private function guaranteedCents(int $orderId): ?int
    {
        $row = OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_AUTHORIZED)
            ?? OrderPriceBreakdown::forStage($orderId, OrderPriceBreakdown::STAGE_ESTIMATE);

        if ($row === null) {
            return null;
        }

        try {
            return (new PricingService())->driverOffer($row)['guaranteed_cents'];
        } catch (PricingException $exception) {
            error_log('[FairPlate] Offer for order ' . $orderId . ' has no guarantee: ' . $exception->getMessage());

            return null;
        }
    }

    /**
     * The order has run out of rounds or minutes. Hand it to a person.
     */
    private function escalate(array $order, string $cause, int $rounds): void
    {
        $orderId = (int) $order['id'];

        $this->stop($orderId);

        try {
            OrderLifecycle::transition($orderId, Order::STATUS_NEEDS_ATTENTION);
        } catch (OrderLifecycleException $exception) {
            // The order moved on between the lock and here — delivered by hand,
            // cancelled by an admin. Nothing left to escalate.
            error_log('[FairPlate] Dispatch could not escalate order ' . $orderId . ': ' . $exception->getMessage());

            return;
        }

        Activity::log('dispatch.needs_attention', 'Order', $orderId, [
            'cause' => $cause,
            'rounds' => $rounds,
        ]);
    }

    /**
     * Is this order still waiting for somebody to carry it?
     *
     * ready and accepted both qualify: the spec allows the kitchen to finish the
     * food before or after a driver is found.
     */
    private function awaitingDriver(array $order): bool
    {
        return ($order['driver_id'] ?? null) === null
            && in_array(
                (string) $order['status'],
                [Order::STATUS_ACCEPTED, Order::STATUS_READY],
                true
            );
    }

    /**
     * Has the dispatch clock run out?
     *
     * It starts when the kitchen accepts, which is the first moment there is
     * anything to dispatch, and it is read off the order rather than kept in
     * memory, so a worker restart cannot give an order a fresh eight minutes.
     */
    private function outOfTime(array $order): bool
    {
        $startedAt = $order['accepted_at'] ?? $order['placed_at'] ?? null;

        if ($startedAt === null) {
            return false;
        }

        $started = strtotime((string) $startedAt . ' UTC');

        if ($started === false) {
            return false;
        }

        return time() - $started >= $this->maxMinutes() * 60;
    }

    /**
     * Why an offer that is no longer pending is no longer pending.
     *
     * Worth the extra query, because the driver is shown this sentence. An
     * offer closed by expireOthersForOrder() is marked expired exactly like one
     * whose clock ran out, and the two are completely different things to the
     * person holding the phone: one means they were too slow, the other means
     * somebody else was quicker. The order knows which — if it has a driver, it
     * was taken.
     */
    private function whyClosed(array $offer): DispatchException
    {
        if ((string) $offer['response'] !== DispatchOffer::RESPONSE_EXPIRED) {
            return DispatchException::answered();
        }

        $order = Order::find((int) $offer['order_id']);

        return ($order !== null && ($order['driver_id'] ?? null) !== null)
            ? DispatchException::taken()
            : DispatchException::expired();
    }

    private function close(int $offerId, string $response): void
    {
        DispatchOffer::update($offerId, [
            'response' => $response,
            'responded_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * One row, held until the transaction ends.
     *
     * The table name is one of three literals written in this class and never
     * anything a caller supplies; the id is bound.
     */
    private function lockRow(PDO $connection, string $table, int $id): ?array
    {
        if (!in_array($table, ['orders', 'drivers', 'dispatch_offers'], true)) {
            throw new \InvalidArgumentException("Dispatch does not lock \"{$table}\".");
        }

        $statement = $connection->prepare('SELECT * FROM `' . $table . '` WHERE id = ? LIMIT 1 FOR UPDATE');
        $statement->execute([$id]);

        return $statement->fetch() ?: null;
    }

    /**
     * Runs the callable inside a transaction, joining one already open rather
     * than nesting — PDO has no nested transactions, and accept() is reachable
     * both from a controller and from a caller that opened its own.
     */
    private function transactional(callable $work): mixed
    {
        $connection = Database::connection();
        $owns = !$connection->inTransaction();

        if ($owns) {
            $connection->beginTransaction();
        }

        try {
            $result = $work($connection);
        } catch (\Throwable $exception) {
            if ($owns && $connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }

        if ($owns) {
            $connection->commit();
        }

        return $result;
    }

    private function offerTimeoutSeconds(): int
    {
        return max(5, Settings::int('offer_timeout_seconds'));
    }

    private function maxRounds(): int
    {
        return max(1, Settings::int('dispatch_max_rounds'));
    }

    private function maxMinutes(): int
    {
        return max(1, Settings::int('dispatch_max_minutes'));
    }
}
