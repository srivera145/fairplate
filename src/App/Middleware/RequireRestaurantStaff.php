<?php

namespace Keel\App\Middleware;

use Keel\App\Models\User;

/** Restricts a route group to the kitchen area (/kitchen). */
class RequireRestaurantStaff extends RequireRole
{
    protected function role(): string
    {
        return User::ROLE_RESTAURANT_STAFF;
    }
}
