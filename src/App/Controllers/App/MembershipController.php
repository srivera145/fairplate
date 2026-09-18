<?php

namespace Keel\App\Controllers\App;

use Keel\App\Models\Membership;
use Keel\App\Services\MembershipService;
use Keel\Core\Request;

/**
 * The membership page: what it costs, what it is worth, and the two buttons.
 *
 * Neither button does any billing itself. Subscribe opens a Stripe Checkout
 * Session and cancel opens the Customer Portal, so the card form, the proration
 * rules and the cancel flow are all Stripe's, and nothing here has to stay
 * correct as those change. What comes back is a webhook.
 *
 * The page shows this month's savings whether or not they beat the price,
 * because a member is entitled to know what their membership did and a
 * non-member is entitled to a number that is not a sales pitch.
 */
class MembershipController extends CustomerController
{
    public function index(Request $request): void
    {
        $userId = $this->userId();
        $service = new MembershipService();
        $membership = $service->membershipFor($userId);

        $this->view('app.membership', array_merge(
            $this->shell('membership', 'Membership'),
            [
                'membership' => $membership,
                'isMember' => Membership::entitles($membership),
                'isEnding' => Membership::isEnding($membership),
                'priceCents' => $this->priceCents($service),
                'benefits' => $service->benefits(),
                'savedThisMonthCents' => $this->savedThisMonth($service, $userId),
                'justSubscribed' => (string) $request->input('subscribed', '') === '1',
            ]
        ));
    }

    /**
     * Off to Stripe to subscribe.
     */
    public function subscribe(Request $request): void
    {
        try {
            $session = (new MembershipService())->checkoutSession($this->user());
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Membership checkout failed: ' . $exception->getMessage());

            $this->back('/app/membership', 'Memberships are unavailable right now. Try again shortly.', 'bad');
        }

        $this->redirect((string) $session->url);
    }

    /**
     * Off to Stripe to change the card, see the invoices, or cancel.
     */
    public function portal(Request $request): void
    {
        try {
            $session = (new MembershipService())->portalSession($this->user());
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Membership portal failed: ' . $exception->getMessage());

            $this->back('/app/membership', 'The billing portal is unavailable right now. Try again shortly.', 'bad');
        }

        $this->redirect((string) $session->url);
    }

    /**
     * The price, or null when nobody has configured one.
     *
     * Settings throws on a missing key by design, and the right answer on this
     * screen is to say the membership is not available rather than to invent a
     * number or return a 500.
     */
    private function priceCents(MembershipService $service): ?int
    {
        try {
            return $service->priceCents();
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Membership price is not configured: ' . $exception->getMessage());

            return null;
        }
    }

    /**
     * Platform fees this month, plus the processing they carried — the gross
     * figure, before the membership price is taken off. The page decides how to
     * say it.
     */
    private function savedThisMonth(MembershipService $service, int $userId): ?int
    {
        try {
            return $service->savingsThisMonthCents($userId) + $service->priceCents();
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
