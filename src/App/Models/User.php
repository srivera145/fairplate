<?php

namespace Keel\App\Models;

use Keel\Core\Database;

class User
{
    public const ROLE_CUSTOMER = 'customer';
    public const ROLE_RESTAURANT_STAFF = 'restaurant_staff';
    public const ROLE_DRIVER = 'driver';
    public const ROLE_ADMIN = 'admin';

    public const ROLES = [
        self::ROLE_CUSTOMER,
        self::ROLE_RESTAURANT_STAFF,
        self::ROLE_DRIVER,
        self::ROLE_ADMIN,
    ];

    /** Where each role lands after signing in. */
    public const ROLE_HOMES = [
        self::ROLE_CUSTOMER => '/app',
        self::ROLE_RESTAURANT_STAFF => '/kitchen',
        self::ROLE_DRIVER => '/drive',
        self::ROLE_ADMIN => '/admin',
    ];

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Phone is the FairPlate sign-in handle, stored in E.164.
     */
    public static function findByPhone(string $phone): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE phone = ? LIMIT 1');
        $stmt->execute([$phone]);
        return $stmt->fetch() ?: null;
    }

    public static function findByStripeCustomerId(string $stripeCustomerId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE stripe_customer_id = ? LIMIT 1');
        $stmt->execute([$stripeCustomerId]);
        return $stmt->fetch() ?: null;
    }

    public static function forOrganization(int $organizationId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE organization_id = ? ORDER BY created_at ASC, id ASC');
        $stmt->execute([$organizationId]);
        return $stmt->fetchAll();
    }

    public static function withRole(string $role): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE role = ? ORDER BY id ASC');
        $stmt->execute([$role]);
        return $stmt->fetchAll();
    }

    public static function createWithPhone(string $phone, string $role = self::ROLE_CUSTOMER, ?string $name = null, ?string $email = null): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (name, email, phone, role, created_at) VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$name, $email, $phone, self::normalizeRole($role)]);

        return (int) Database::connection()->lastInsertId();
    }

    public static function setRole(int $id, string $role): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([self::normalizeRole($role), $id]);
    }

    /**
     * The name a customer will see on a delivery, and a kitchen on a board.
     *
     * Sign-in is by phone, so a FairPlate account can exist with no name at all
     * until the person is somewhere that needs one. Onboarding is that place.
     */
    public static function updateName(int $id, string $name): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
    }

    public static function updateThemePreference(int $id, string $theme): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET theme_preference = ? WHERE id = ?');
        $stmt->execute([$theme, $id]);
    }

    public static function hasRole(?array $user, string $role): bool
    {
        return $user !== null && (string) ($user['role'] ?? '') === $role;
    }

    /**
     * The route group this user's role owns.
     */
    public static function homePath(?array $user): string
    {
        $role = (string) ($user['role'] ?? '');

        return self::ROLE_HOMES[$role] ?? '/login';
    }

    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));

        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException("Unknown role \"{$role}\".");
        }

        return $role;
    }
}
