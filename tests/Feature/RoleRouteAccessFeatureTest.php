<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\User;
use Tests\TestCase;

/**
 * Every role reaches its own area and nothing else.
 *
 * Four roles against four areas is sixteen combinations: four that must work
 * and twelve that must come back 403. All sixteen are checked here.
 */
class RoleRouteAccessFeatureTest extends TestCase
{
    private const AREAS = [
        User::ROLE_CUSTOMER => '/app',
        User::ROLE_RESTAURANT_STAFF => '/kitchen',
        User::ROLE_DRIVER => '/drive',
        User::ROLE_ADMIN => '/admin',
    ];

    public function testEachRoleReachesItsOwnArea(): void
    {
        foreach (self::AREAS as $role => $path) {
            $this->setUp();
            $this->actingAsRole($role);

            $response = $this->get($path);

            self::assertSame(200, $response->status, "{$role} should reach {$path}");
        }
    }

    public function testEveryCrossRoleCombinationIsForbidden(): void
    {
        $checked = 0;

        foreach (self::AREAS as $role => $ownPath) {
            foreach (self::AREAS as $otherRole => $otherPath) {
                if ($role === $otherRole) {
                    continue;
                }

                $this->setUp();
                $this->actingAsRole($role);

                $response = $this->get($otherPath);

                self::assertSame(403, $response->status, "{$role} must not reach {$otherPath}");
                $checked++;
            }
        }

        self::assertSame(12, $checked, 'All twelve cross-role combinations must be exercised.');
    }

    public function testSignedOutVisitorsAreSentToSignIn(): void
    {
        foreach (self::AREAS as $path) {
            $this->setUp();

            $response = $this->get($path);

            self::assertSame(302, $response->status);
            self::assertSame('/login', $response->header('Location'));
        }
    }

    public function testJsonClientsGetStatusCodesRatherThanRedirects(): void
    {
        $this->actingAsRole(User::ROLE_DRIVER);

        $forbidden = $this->getJson('/admin');
        self::assertSame(403, $forbidden->status);
        self::assertSame('Forbidden.', $forbidden->json()['error'] ?? null);

        $this->setUp();

        $unauthenticated = $this->getJson('/app');
        self::assertSame(401, $unauthenticated->status);
        self::assertSame('Unauthenticated.', $unauthenticated->json()['error'] ?? null);
    }
}
