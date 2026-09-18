<?php

namespace Keel\App\Services;

use Keel\App\Models\User;
use Keel\Core\Database;
use Keel\Core\Env;
use Keel\Core\Sms;

/**
 * Sign-in by phone OTP.
 *
 * This is Keel's OTP flow with SMS in place of email: same auth_tokens table,
 * same 'otp' token type, same hash-and-compare, same window and rate limit. The
 * code is never stored in the clear, and verification walks only unused,
 * unexpired tokens for that user.
 *
 * A number that has never been seen becomes a customer. The other three roles
 * are granted by an admin or the seeder, never by signing in.
 */
class PhoneOtpService
{
    private const TOKEN_TYPE = 'otp';
    private const EXPIRY_MINUTES = 10;
    private const RATE_LIMIT_WINDOW_MINUTES = 15;
    private const RATE_LIMIT_MAX = 5;

    public function requestCode(string $phone): array
    {
        $normalized = Sms::normalize($phone);

        if ($normalized === null) {
            return ['success' => false, 'message' => 'Enter a valid phone number.'];
        }

        $user = $this->findOrCreateUser($normalized);

        if ($this->tooManyRequests((int) $user['id'])) {
            return ['success' => false, 'message' => 'Too many requests. Please try again later.'];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::EXPIRY_MINUTES . ' minutes'));

        $statement = Database::connection()->prepare(
            'INSERT INTO auth_tokens (user_id, type, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())'
        );
        $statement->execute([
            $user['id'],
            self::TOKEN_TYPE,
            password_hash($code, PASSWORD_DEFAULT),
            $expiresAt,
        ]);

        $appName = (string) Env::get('APP_NAME', 'FairPlate');
        $sent = Sms::send($normalized, "{$appName} code: {$code}. It expires in " . self::EXPIRY_MINUTES . ' minutes.');

        return [
            'success' => $sent,
            'message' => $sent ? 'Code sent.' : 'Could not send the code. Try again shortly.',
        ];
    }

    public function verifyCode(string $phone, string $code): array
    {
        $normalized = Sms::normalize($phone);

        if ($normalized === null) {
            return ['success' => false, 'message' => 'Invalid code.'];
        }

        $user = User::findByPhone($normalized);

        if (!$user) {
            return ['success' => false, 'message' => 'Invalid code.'];
        }

        $statement = Database::connection()->prepare(
            'SELECT * FROM auth_tokens
             WHERE user_id = ? AND type = ? AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC
             LIMIT 5'
        );
        $statement->execute([$user['id'], self::TOKEN_TYPE]);

        foreach ($statement->fetchAll() as $token) {
            if (!password_verify($code, (string) $token['token_hash'])) {
                continue;
            }

            $update = Database::connection()->prepare('UPDATE auth_tokens SET used_at = NOW() WHERE id = ?');
            $update->execute([$token['id']]);

            return ['success' => true, 'user' => $user];
        }

        return ['success' => false, 'message' => 'Invalid or expired code.'];
    }

    private function tooManyRequests(int $userId): bool
    {
        $windowStart = date('Y-m-d H:i:s', strtotime('-' . self::RATE_LIMIT_WINDOW_MINUTES . ' minutes'));

        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) AS cnt FROM auth_tokens WHERE user_id = ? AND type = ? AND created_at > ?'
        );
        $statement->execute([$userId, self::TOKEN_TYPE, $windowStart]);

        return (int) $statement->fetch()['cnt'] >= self::RATE_LIMIT_MAX;
    }

    private function findOrCreateUser(string $phone): array
    {
        $user = User::findByPhone($phone);

        if ($user) {
            return $user;
        }

        User::createWithPhone($phone, User::ROLE_CUSTOMER);

        return User::findByPhone($phone) ?? [];
    }
}
