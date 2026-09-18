<?php

declare(strict_types=1);

namespace Tests\Feature;

use Keel\App\Models\User;
use Keel\Core\Database;
use Tests\TestCase;

class PhoneOtpFeatureTest extends TestCase
{
    private function requestAndReadCode(string $phone): string
    {
        $response = $this->postJson('/auth/phone/request', ['phone' => $phone], [
            'X-CSRF-Token' => $this->csrfToken(),
        ]);

        self::assertSame(200, $response->status, 'requesting a code should succeed');
        self::assertTrue((bool) ($response->json()['success'] ?? false));

        preg_match('/\b(\d{6})\b/', $this->latestSmsLog(), $matches);

        self::assertArrayHasKey(1, $matches, 'the SMS log should carry a six-digit code');

        return $matches[1];
    }

    public function testAnUnknownNumberBecomesACustomerAndLandsInTheCustomerArea(): void
    {
        $phone = '(850) 555-0199';

        $code = $this->requestAndReadCode($phone);

        $verify = $this->postJson('/auth/phone/verify', ['phone' => $phone, 'code' => $code], [
            'X-CSRF-Token' => $this->csrfToken(),
        ]);

        self::assertSame(200, $verify->status);
        self::assertSame('/app', $verify->json()['redirect'] ?? null);

        $user = User::findByPhone('+18505550199');
        self::assertIsArray($user);
        self::assertSame(User::ROLE_CUSTOMER, (string) $user['role']);
        self::assertSame((int) $user['id'], (int) $_SESSION['user_id']);
    }

    public function testEachRoleSignsInAndLandsInItsOwnArea(): void
    {
        $cases = [
            [User::ROLE_CUSTOMER, '+18505550701', '/app'],
            [User::ROLE_RESTAURANT_STAFF, '+18505550702', '/kitchen'],
            [User::ROLE_DRIVER, '+18505550703', '/drive'],
            [User::ROLE_ADMIN, '+18505550704', '/admin'],
        ];

        foreach ($cases as [$role, $phone, $home]) {
            $this->setUp();

            User::createWithPhone($phone, $role, ucfirst($role) . ' Tester');

            $code = $this->requestAndReadCode($phone);

            $verify = $this->postJson('/auth/phone/verify', ['phone' => $phone, 'code' => $code], [
                'X-CSRF-Token' => $this->csrfToken(),
            ]);

            self::assertSame(200, $verify->status, "{$role} should verify");
            self::assertSame($home, $verify->json()['redirect'] ?? null, "{$role} should land on {$home}");

            // And the session it just established really opens that door.
            $dashboard = $this->get($home);
            self::assertSame(200, $dashboard->status, "{$role} should reach {$home} after signing in");
        }
    }

    public function testTheCodeIsNeverStoredInTheClear(): void
    {
        $phone = '+18505550801';
        $code = $this->requestAndReadCode($phone);

        $stored = Database::connection()->query('SELECT token_hash FROM auth_tokens ORDER BY id DESC LIMIT 1')->fetchColumn();

        self::assertNotSame($code, (string) $stored);
        self::assertTrue(password_verify($code, (string) $stored));
    }

    public function testAWrongCodeIsRejected(): void
    {
        $phone = '+18505550802';
        $code = $this->requestAndReadCode($phone);
        $wrong = str_pad((string) ((((int) $code) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        $verify = $this->postJson('/auth/phone/verify', ['phone' => $phone, 'code' => $wrong], [
            'X-CSRF-Token' => $this->csrfToken(),
        ]);

        self::assertSame(422, $verify->status);
        self::assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testACodeCannotBeUsedTwice(): void
    {
        $phone = '+18505550803';
        $code = $this->requestAndReadCode($phone);

        $first = $this->postJson('/auth/phone/verify', ['phone' => $phone, 'code' => $code], [
            'X-CSRF-Token' => $this->csrfToken(),
        ]);
        self::assertSame(200, $first->status);

        $second = $this->postJson('/auth/phone/verify', ['phone' => $phone, 'code' => $code], [
            'X-CSRF-Token' => $this->csrfToken(),
        ]);
        self::assertSame(422, $second->status);
    }

    public function testAnUnparseableNumberIsRejectedWithoutCreatingAUser(): void
    {
        $response = $this->postJson('/auth/phone/request', ['phone' => '123'], [
            'X-CSRF-Token' => $this->csrfToken(),
        ]);

        self::assertSame(422, $response->status);
        self::assertSame(0, (int) Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testRequestingACodeRequiresCsrf(): void
    {
        $response = $this->post('/auth/phone/request', ['phone' => '+18505550804']);

        self::assertSame(419, $response->status);
    }
}
