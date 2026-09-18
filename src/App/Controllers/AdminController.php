<?php

namespace Keel\App\Controllers;

use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Models\Payout;
use Keel\App\Models\Restaurant;
use Keel\App\Models\Setting;
use Keel\App\Models\User;
use Keel\App\Services\Payments\PaymentException;
use Keel\App\Services\Payments\PaymentService;
use Keel\App\Services\Payments\PayoutService;
use Keel\App\Services\Pricing\Money;
use Keel\App\Services\Pricing\PricingException;
use Keel\Core\Activity;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Request;
use Keel\Core\Response;
use Keel\Core\Session;

/**
 * The admin area.
 *
 * Still a placeholder for the operations console, with three exceptions that
 * could not wait for it, each because without it something real has nowhere to
 * happen: approving a driver, refunding an order, and sending a payout that got
 * stuck.
 *
 * The money half is here because the spec puts two decisions in a person's
 * hands and nowhere else. Whether to refund an order is one. Whether a refund
 * was the restaurant's fault is the other, and it is the only thing in the whole
 * application that takes money back off a kitchen — so it is a checkbox somebody
 * ticks deliberately rather than anything inferred.
 */
class AdminController extends Controller
{
    /** Where a flash message waits between the POST and the redirect. */
    private const FLASH_KEY = '_admin_flash';

    public function index(Request $request): void
    {
        $this->view('admin.dashboard', [
            'title' => 'FairPlate Admin',
            'user' => User::find((int) Auth::id()),
            'restaurantCount' => Restaurant::count(),
            'driverCount' => Driver::count(),
            'orderCount' => Order::count(),
            'pendingDrivers' => Driver::awaitingApproval(),
            'capturedOrders' => Order::recentlyCaptured(),
            'stuckPayouts' => Payout::needingAttention(),
            'settings' => Setting::all(),
            'flash' => $this->takeFlash(),
        ]);
    }

    /**
     * Gives a customer their money back, and decides who pays for it.
     *
     * The second half is the only decision on this screen that cannot be undone
     * from here, so it is a deliberate checkbox rather than a default. The
     * platform absorbs a refund unless an admin says the kitchen caused it, and
     * only then does anything come back out of the restaurant's transfer — the
     * driver's stands either way, because the driver drove.
     *
     * The amount is typed in dollars and converted once, by Money. There is no
     * path here by which a number becomes a float.
     */
    public function refundOrder(Request $request, string $id): void
    {
        $orderId = (int) $id;
        $order = Order::find($orderId);

        if ($order === null) {
            $this->back('Order ' . $orderId . ' does not exist.', 'bad');
        }

        try {
            $amountCents = Money::fromDollars((string) ($_POST['amount'] ?? ''));
        } catch (PricingException $exception) {
            $this->back('Enter an amount in dollars, like 12.50.', 'bad');
        }

        $restaurantError = ($_POST['restaurant_error'] ?? '') !== '';
        $reason = trim((string) ($_POST['reason'] ?? ''));

        try {
            $refund = (new PaymentService())->refund($order, $amountCents, $reason, $restaurantError);
        } catch (PaymentException $exception) {
            $this->back($exception->getMessage(), 'bad');
        }

        $this->back(sprintf(
            'Refunded %s on order %d.%s',
            Money::usd((int) $refund['amount_cents']),
            $orderId,
            $restaurantError
                ? ' ' . Money::usd((int) $refund['reversed_restaurant_cents']) . ' came back from the restaurant.'
                : ' The platform absorbed it.'
        ));
    }

    /**
     * Puts a stuck payout back in the queue.
     *
     * For the case account.updated cannot cover: a payout held because nobody
     * had connected an account at all, or one that failed its five attempts
     * against something since fixed. The money was never dropped — it has been
     * sitting on its row — and this is the button that sends it.
     */
    public function retryPayout(Request $request, string $id): void
    {
        $payout = Payout::find((int) $id);

        if ($payout === null) {
            $this->back('That payout does not exist.', 'bad');
        }

        // release() only takes held rows; a failed one is put back to held
        // first, so both paths go through the same door.
        if ((string) $payout['status'] === Payout::STATUS_FAILED) {
            Payout::update((int) $payout['id'], ['status' => Payout::STATUS_HELD]);
            $payout = Payout::find((int) $payout['id']) ?? $payout;
        }

        if (!(new PayoutService())->release($payout)) {
            $this->back('That payout is not waiting to be sent.', 'bad');
        }

        $this->back('Payout ' . (int) $payout['id'] . ' is queued again.');
    }

    /**
     * Clears a driver to take offers.
     *
     * The one piece of the operations console that could not wait for it. A
     * driver who has filled in the form and connected Stripe cannot work until
     * somebody says so — that is the spec's rule and it is the right one — but
     * without this there is nobody to say it, and the whole driver app is a
     * screen that reads "pending approval" forever.
     *
     * Approval only. There is deliberately no button here for the other
     * direction: taking a driver's income away mid-shift deserves a screen that
     * shows what they are carrying and what they are owed, and that belongs with
     * the console rather than bolted on here.
     */
    public function approveDriver(Request $request, string $id): void
    {
        $driverId = (int) $id;
        $driver = Driver::find($driverId);

        if ($driver === null) {
            Response::redirect('/admin');
        }

        if (!Driver::isApproved($driver)) {
            Driver::update($driverId, ['approved' => 1]);
            Activity::log('driver.approved', 'Driver', $driverId, [
                'user_id' => (int) $driver['user_id'],
            ]);
        }

        Response::redirect('/admin');
    }

    /**
     * A one-shot message carried back to the dashboard.
     *
     * The customer app's CustomerController has the same pair for the same
     * reason; admin screens are POST-then-redirect too, and a refund that
     * silently reloads the page is one nobody can tell succeeded.
     */
    private function back(string $message, string $tone = 'good'): never
    {
        Session::put(self::FLASH_KEY, ['message' => $message, 'tone' => $tone]);

        Response::redirect('/admin');
    }

    /**
     * @return array{message: string, tone: string}|null
     */
    private function takeFlash(): ?array
    {
        $flash = Session::get(self::FLASH_KEY);
        Session::forget(self::FLASH_KEY);

        return is_array($flash) ? $flash : null;
    }
}
