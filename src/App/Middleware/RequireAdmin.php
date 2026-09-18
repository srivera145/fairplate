<?php

namespace Keel\App\Middleware;

use Keel\App\Models\User;

/** Restricts a route group to the admin area (/admin). */
class RequireAdmin extends RequireRole
{
    protected function role(): string
    {
        return User::ROLE_ADMIN;
    }
}
