<?php

namespace Keel\App\Controllers\App;

use Keel\App\Models\Address;
use Keel\App\Models\Membership;
use Keel\App\Models\User;
use Keel\App\Services\CartService;
use Keel\Core\Auth;
use Keel\Core\Controller;
use Keel\Core\Response;
use Keel\Core\Session;
use Keel\Core\View;

/**
 * What every customer screen needs before it can do anything.
 *
 * RequireCustomer on the route group answers "is this a customer". It cannot
 * answer "is this *their* order", because that depends on the id in the URL,
 * and every /app URL that carries one is carrying a key to a table shared by
 * every customer on the platform. That second question is asked here, and the
 * answer to a no is a 404 rather than a 403: telling somebody that order 812
 * exists but is not theirs is telling them something.
 *
 * The chrome — the cart count, whether they are a member, the delivery address
 * the screen is working against — is the same on every page, so shell() builds
 * it once and every view reads the same keys.
 */
abstract class CustomerController extends Controller
{
    /** Where a flash message waits between the POST and the redirect. */
    private const FLASH_KEY = '_app_flash';

    protected function userId(): int
    {
        return (int) Auth::id();
    }

    protected function user(): array
    {
        return User::find($this->userId()) ?? [];
    }

    protected function cart(): CartService
    {
        return new CartService();
    }

    /**
     * The data every customer view's chrome needs.
     *
     * @return array<string, mixed>
     */
    protected function shell(string $active, string $title): array
    {
        $userId = $this->userId();

        return [
            'title' => $title . ' · FairPlate',
            'heading' => $title,
            'activeNav' => $active,
            'user' => $this->user(),
            'cartCount' => $this->cart()->count($userId),
            'isMember' => Membership::isActiveFor($userId),
            'deliveryAddress' => Address::defaultForUser($userId),
            'flash' => $this->takeFlash(),
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

    /**
     * Somebody else's record, or no record at all. The customer is told the same
     * thing either way.
     */
    protected function notFound(): never
    {
        if ($this->wantsJson()) {
            Response::json(['error' => 'Not found.'], 404);
        }

        Response::raw($this->errorPage('errors.404'), 404, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    protected function forbidden(): never
    {
        if ($this->wantsJson()) {
            Response::json(['error' => 'Forbidden.'], 403);
        }

        Response::raw($this->errorPage('errors.403'), 403, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Renders a view into a string, for a JSON response that hands back markup
     * rather than asking a script to build it.
     */
    protected function renderToString(string $template, array $data = []): string
    {
        ob_start();

        try {
            View::render($template, $data);
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        return (string) ob_get_clean();
    }

    private function errorPage(string $template): string
    {
        ob_start();

        try {
            View::render($template, ['homePath' => '/app']);
        } catch (\Throwable $exception) {
            ob_end_clean();

            return 'Not found';
        }

        return (string) ob_get_clean();
    }
}
