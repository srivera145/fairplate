<?php

namespace Keel\App\Middleware;

use Keel\App\Models\User;

/** Restricts a route group to the driver area (/drive). */
class RequireDriver extends RequireRole
{
    protected function role(): string
    {
        return User::ROLE_DRIVER;
    }
}
