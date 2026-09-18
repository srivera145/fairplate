<?php

namespace Keel\App\Controllers\Drive;

use Keel\App\Models\DispatchOffer;
use Keel\App\Models\Driver;
use Keel\App\Models\Order;
use Keel\App\Models\User;
use Keel\App\Services\Settings;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Response;
use Keel\Core\Session;
use Keel\Core\View;

/**
 * What every driver screen needs before it can do anything.
 *
 * RequireDriver on the route group answers "is this a driver". It cannot answer
 * "is this *their* offer", because that depends on the id in the URL, and every
 * /drive URL that carries one is carrying a key to a table shared by every
 * driver on the platform. That second question is asked here.
 *
 * The answer to a no is a flat 403 rather than the customer app's 404. The two
 * are different on purpose: a customer guessing order ids should not learn which
 * ones exist, whereas a driver who followed a stale link to an order somebody
 * else took needs to be told that plainly, because it is going to happen to
 * every driver who hesitates over a card and it is not a mystery worth
 * preserving.
 *
 * The screens themselves are built for one thumb on a phone that is also
 * running two other delivery apps. Everything below is in service of that:
 * shell() carries the poll intervals so no view invents its own, and there is
 * one flash key so a message survives exactly one redirect and then stops.
 */
abstract class DriverController extends Controller
{
    /** Where a flash message waits between the POST and the redirect. */
    private const FLASH_KEY = '_drive_flash';

    /** Stored times are UTC; a driver's day is not. */
    protected const TIMEZONE = 'America/New_York';

    /**
     * How often the home screen asks whether there is an offer.
     *
     * Three seconds, per the spec, and it is the one interval here that is not a
     * setting. It is not an operational knob but the product: an offer lives for
     * forty-five seconds, and a driver who sees it nine seconds late has lost a
     * fifth of the time they were promised to decide.
     */
    public const OFFER_POLL_SECONDS = 3;

    protected function userId(): int
    {
        return (int) Auth::id();
    }

    protected function user(): array
    {
        return User::find($this->userId()) ?? [];
    }

    /**
     * This user's driver record, or null when they have not started onboarding.
     *
     * Null is a real state with a screen of its own, not an error: a driver
     * signs in for the first time with a user row and nothing else.
     */
    protected function driver(): ?array
    {
        return Driver::forUser($this->userId());
    }

    /**
     * The driver record, or a 403 for the routes that make no sense without one.
     */
    protected function requireDriverProfile(): array
    {
        $driver = $this->driver();

        if ($driver === null) {
            $this->forbidden();
        }

        return $driver;
    }

    /**
     * The driver record, and they are cleared to work.
     *
     * Everything that moves an order goes through here. Approval is checked on
     * each action rather than once at sign-in, so a driver an admin suspends
     * mid-shift stops being able to take work at the next tap rather than at the
     * next sign-in.
     */
    protected function requireApprovedDriver(): array
    {
        $driver = $this->requireDriverProfile();

        if (!Driver::isApproved($driver)) {
            $this->forbidden();
        }

        return $driver;
    }

    /**
     * One of this driver's orders, or a 403.
     */
    protected function ownedOrder(int $driverId, int $orderId): array
    {
        $order = Order::forDriverAndId($driverId, $orderId);

        if ($order === null) {
            $this->forbidden();
        }

        return $order;
    }

    /**
     * One of this driver's offers, or a 403.
     */
    protected function ownedOffer(int $driverId, int $offerId): array
    {
        $offer = DispatchOffer::forDriverAndId($driverId, $offerId);

        if ($offer === null) {
            $this->forbidden();
        }

        return $offer;
    }

    /**
     * The data every driver view's chrome needs.
     *
     * @return array<string, mixed>
     */
    protected function shell(?array $driver, string $active, string $title): array
    {
        return [
            'title' => $title . ' · FairPlate Drive',
            'heading' => $title,
            'activeNav' => $active,
            'user' => $this->user(),
            'driver' => $driver,
            'flash' => $this->takeFlash(),
            'offerPollSeconds' => self::OFFER_POLL_SECONDS,
        ];
    }

    /**
     * A ping interval from settings, floored at a second the browser will
     * actually honour and a battery will survive.
     *
     * Read by the screens that actually report a position, and not by shell().
     * Settings throws on a missing key rather than inventing one — which is
     * right, and is why this is not in the chrome: a driver signing in to a
     * half-configured deployment should get the page that tells them to finish
     * signing up, not a 500 from a number nothing on that page was going to
     * use.
     */
    protected function pingSeconds(string $key): int
    {
        return max(5, Settings::int($key));
    }

    /**
     * The UTC half-open bounds of a driver's day.
     *
     * Resolved in PHP against the real zone and compared against stored UTC, the
     * same way the restaurant's billing month is, so a shift that ends at one in
     * the morning lands on the day the driver thinks they worked.
     *
     * @return array{0: string, 1: string}
     */
    protected function dayBoundsUtc(?\DateTimeImmutable $at = null): array
    {
        $start = $this->localNow($at)->setTime(0, 0, 0);

        return $this->toUtcBounds($start, $start->modify('+1 day'));
    }

    /**
     * The same, for the week the day falls in. Weeks start Monday, which is what
     * PHP's "this week" means and what a pay week normally means.
     *
     * @return array{0: string, 1: string}
     */
    protected function weekBoundsUtc(?\DateTimeImmutable $at = null): array
    {
        $start = $this->localNow($at)->setTime(0, 0, 0)->modify('monday this week');

        return $this->toUtcBounds($start, $start->modify('+7 days'));
    }

    protected function localNow(?\DateTimeImmutable $at = null): \DateTimeImmutable
    {
        $zone = new \DateTimeZone(self::TIMEZONE);

        return $at === null ? new \DateTimeImmutable('now', $zone) : $at->setTimezone($zone);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function toUtcBounds(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * A one-shot message carried across a redirect.
     */
    protected function flash(string $message, string $tone = 'good'): void
    {
        Session::put(self::FLASH_KEY, ['message' => $message, 'tone' => $tone]);
    }

    /**
     * @return array{message: string, tone: string}|null
     */
    protected function takeFlash(): ?array
    {
        $flash = Session::get(self::FLASH_KEY);
        Session::forget(self::FLASH_KEY);

        return is_array($flash) ? $flash : null;
    }

    /**
     * Saves a message and goes back to where the form was.
     */
    protected function back(string $to, string $message = '', string $tone = 'good'): never
    {
        if ($message !== '') {
            $this->flash($message, $tone);
        }

        Response::redirect($to);
    }

    protected function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    protected function forbidden(): never
    {
        if ($this->wantsJson()) {
            Response::json(['error' => 'Forbidden.'], 403);
        }

        Response::raw($this->forbiddenPage(), 403, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Renders a view into a string, for a JSON response that hands back markup
     * rather than asking a script to build it.
     */
    protected function renderToString(string $template, array $data = []): string
    {
        // A polled partial renders without ever going through partials/head.php,
        // which is where Deck is normally told where its assets live. Without
        // this the icons in the swapped-in card point at Deck's default base and
        // quietly 404. The values match head.php's, and are the only two Deck
        // needs to resolve an icon sprite.
        \EchoDial\Deck\Deck::configure([
            'base' => '/deck',
            'root' => dirname(__DIR__, 4) . '/public_html',
        ]);

        ob_start();

        try {
            View::render($template, $data);
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        return (string) ob_get_clean();
    }

    private function forbiddenPage(): string
    {
        ob_start();

        try {
            View::render('errors.403', ['homePath' => '/drive']);
        } catch (\Throwable $exception) {
            ob_end_clean();

            return 'Forbidden';
        }

        return (string) ob_get_clean();
    }
}
