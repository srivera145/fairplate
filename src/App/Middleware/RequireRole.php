<?php

namespace Keel\App\Middleware;

use Keel\App\Models\User;
use Keel\Core\Middleware;
use Keel\Core\Request;
use Keel\Core\Response;
use Keel\Core\Session;
use Keel\Core\View;

/**
 * Gate for the four FairPlate route groups.
 *
 * Keel's router builds middleware with `new $class()` and no arguments, so the
 * role cannot be passed in; each role gets a one-line subclass instead and the
 * route group names the subclass.
 *
 * Signed out is a redirect to the sign-in page. Signed in as the wrong role is
 * a flat 403, not a redirect to that role's own area: a driver poking at
 * /admin should be told no, not quietly bounced somewhere that looks like it
 * worked.
 */
abstract class RequireRole implements Middleware
{
    abstract protected function role(): string;

    public function handle(Request $request, \Closure $next): mixed
    {
        $userId = Session::get('user_id');

        if (!$userId) {
            if ($request->wantsJson()) {
                Response::json(['error' => 'Unauthenticated.'], 401);
            }

            Response::redirect('/login');
        }

        $user = User::find((int) $userId);

        if ($user === null) {
            Session::destroy();

            if ($request->wantsJson()) {
                Response::json(['error' => 'Unauthenticated.'], 401);
            }

            Response::redirect('/login');
        }

        if (!User::hasRole($user, $this->role())) {
            if ($request->wantsJson()) {
                Response::json(['error' => 'Forbidden.'], 403);
            }

            Response::raw($this->forbiddenPage($user), 403, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        return $next($request);
    }

    /**
     * The 403 page, with a way back to whatever area this user does own.
     */
    private function forbiddenPage(array $user): string
    {
        ob_start();

        try {
            View::render('errors.403', ['homePath' => User::homePath($user)]);
        } catch (\Throwable $exception) {
            ob_end_clean();

            return 'Forbidden';
        }

        return (string) ob_get_clean();
    }
}
