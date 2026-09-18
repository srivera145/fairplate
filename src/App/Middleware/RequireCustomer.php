<?php

namespace Keel\App\Middleware;

use Keel\App\Models\User;

/** Restricts a route group to the customer area (/app). */
class RequireCustomer extends RequireRole
{
    protected function role(): string
    {
        return User::ROLE_CUSTOMER;
    }
}
