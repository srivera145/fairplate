<?php

namespace Keel\App\Controllers\Drive;

use Keel\App\Models\DispatchOffer;
use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Services\Dispatch\DispatchException;
use Keel\App\Services\Dispatch\DispatchService;
use Keel\App\Services\Dispatch\OfferCard;
use Keel\Core\Request;

/**
 * The offer card: the poll that finds one, and the two buttons on it.
 *
 * The poll is the only thing in the driver app that runs on a timer of its own,
 * and it hands back rendered markup rather than JSON for the page to assemble.
 * Same arrangement as the kitchen board, for the same reason: there is one
 * description of what an offer looks like and it lives in a view file, so the
 * card on the home screen and the card the poll swaps in cannot drift apart.
 *
 * Accept and Decline are real forms. A driver whose script failed to load on a
 * phone with one bar still has two buttons that work; what they lose is the
 * countdown ring and the automatic appearance of the card, not the ability to
 * take the job.
 *
 * Everything here is scoped twice. The route group says "a driver"; every
 * method then loads the offer by (offer id, driver id) together, so there is no
 * path that reads somebody else's card and then decides whether it should have.
 */
class OffersController extends DriverController
{
    /**
     * Is there an offer? Asked every three seconds while online.
     *
     * The answer carries the whole card, or says there is nothing. It also says
     * when the driver should be somewhere else — an accepted offer, or a
     * delivery that started in another tab — because the page that asked is the
     * page that would otherwise sit showing a switch while the food waits.
     */
    public function current(Request $request): never
    {
        $driver = $this->driver();

        if ($driver === null || !Driver::isApproved($driver)) {
            $this->json(['offer' => false, 'html' => '', 'redirect' => null]);
        }

        $driverId = (int) $driver['id'];
        $active = Order::activeForDriver($driverId);

        if ($active !== null) {
            $this->json([
                'offer' => false,
                'html' => '',
                'redirect' => '/drive/orders/' . (int) $active['id'],
            ]);
        }

        $offer = DispatchOffer::currentForDriver($driverId);
        $card = $offer === null ? null : OfferCard::forOffer($driver, $offer);

        if ($card === null) {
            $this->json(['offer' => false, 'html' => '', 'redirect' => null]);
        }

        $this->json([
            'offer' => true,
            'offer_id' => (int) $offer['id'],
            'seconds_left' => $card['seconds_left'],
            'window_seconds' => $card['window_seconds'],
            'html' => $this->renderToString('drive.partials.offer', $card),
            'redirect' => null,
        ]);
    }

    /**
     * One offer on its own page.
     *
     * The card normally lives on the home screen, but an offer has a URL so that
     * a driver who backgrounded the app and came back to a notification lands on
     * the thing itself rather than on a screen that has to find it again.
     */
    public function show(Request $request, string $id): void
    {
        $driver = $this->requireApprovedDriver();
        $offer = $this->ownedOffer((int) $driver['id'], (int) $id);
        $card = OfferCard::forOffer($driver, $offer);

        if ($card === null || (string) $offer['response'] !== DispatchOffer::RESPONSE_PENDING) {
            $this->back('/drive', 'That offer is no longer open.', 'warn');
        }

        $this->view('drive.offer', array_merge(
            $this->shell($driver, 'home', 'Offer'),
            [
                'card' => $card,
                'onlinePingSeconds' => $this->pingSeconds('location_ping_online_seconds'),
            ]
        ));
    }

    /**
     * Take it.
     *
     * Every way this can fail is somebody else having been a second quicker, so
     * every failure is a sentence and a trip back to the home screen rather than
     * an error page. DispatchService carries the reason on the exception, which
     * is why there is no string matching here.
     */
    public function accept(Request $request, string $id): void
    {
        $driver = $this->requireApprovedDriver();
        $driverId = (int) $driver['id'];
        $offer = $this->ownedOffer($driverId, (int) $id);

        try {
            $order = (new DispatchService())->accept((int) $offer['id'], $driverId);
        } catch (DispatchException $exception) {
            $this->back('/drive', $exception->getMessage(), 'warn');
        }

        $this->back('/drive/orders/' . (int) $order['id'], 'Yours. Head to the restaurant.');
    }

    /**
     * Pass.
     *
     * Declining is a first-class answer, not a failure: a driver who is about to
     * finish another app's run should be able to say no in one tap and have the
     * order reach somebody else immediately, which is what DispatchService does
     * the moment this returns.
     */
    public function decline(Request $request, string $id): void
    {
        $driver = $this->requireApprovedDriver();
        $driverId = (int) $driver['id'];
        $offer = $this->ownedOffer($driverId, (int) $id);

        try {
            (new DispatchService())->decline((int) $offer['id'], $driverId);
        } catch (DispatchException $exception) {
            $this->back('/drive', $exception->getMessage(), 'warn');
        }

        $this->back('/drive', 'Passed. Waiting for the next one.');
    }
}
