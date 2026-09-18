<?php

namespace Keel\App\Services\Payments;

use Keel\App\Models\User;
use Keel\Core\Activity;
use Keel\Core\Mailer;

/**
 * How money trouble reaches a person.
 *
 * Everything in this namespace has the same failure shape: the customer has
 * already eaten, the driver has already driven, and something between here and
 * Stripe did not go through. None of that can be fixed by code — a connected
 * account whose payouts are disabled stays disabled until somebody at the other
 * end finishes their paperwork — so the only correct ending is a person being
 * told, promptly and with enough detail to act.
 *
 * Three channels, deliberately: the activity log, because that is where the
 * operations console will read from; the error log, because that is what the
 * on-call sees first; and email to every admin, because a payout stuck at two
 * in the morning should not wait for somebody to open a dashboard.
 *
 * Alerting never throws. An alert that takes down the caller would turn one
 * stuck payout into a failed job into a retry into the same alert, and the
 * thing it was trying to report would be the least of it.
 */
final class AdminAlert
{
    /**
     * @param array<string, mixed> $context what an admin needs to act on it
     */
    public static function raise(string $action, string $message, array $context = []): void
    {
        error_log('[FairPlate] ALERT ' . $action . ': ' . $message . ' ' . self::encode($context));

        Activity::log('alert.' . $action, self::subjectType($context), self::subjectId($context), [
            'message' => $message,
        ] + $context);

        try {
            self::email($action, $message, $context);
        } catch (\Throwable $exception) {
            error_log('[FairPlate] Could not email the payments alert: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function email(string $action, string $message, array $context): void
    {
        $admins = User::withRole(User::ROLE_ADMIN);

        if ($admins === []) {
            return;
        }

        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $rows = '';

        foreach ($context as $key => $value) {
            $rows .= '<li><strong>' . $escape((string) $key) . ':</strong> '
                . $escape(is_scalar($value) ? (string) $value : self::encode($value)) . '</li>';
        }

        $body = '<p>' . $escape($message) . '</p>'
            . ($rows === '' ? '' : '<ul>' . $rows . '</ul>');

        foreach ($admins as $admin) {
            $email = trim((string) ($admin['email'] ?? ''));

            if ($email === '') {
                continue;
            }

            Mailer::send($email, (string) ($admin['name'] ?? 'Admin'), 'FairPlate: ' . $action, $body);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function subjectType(array $context): ?string
    {
        return isset($context['order_id']) ? 'Order' : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function subjectId(array $context): ?int
    {
        return isset($context['order_id']) ? (int) $context['order_id'] : null;
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
