<?php

namespace Keel\Core;

/**
 * Outbound SMS.
 *
 * FairPlate signs users in by phone, so the OTP goes out over SMS. No provider
 * is wired yet: SMS_DRIVER=log writes to storage/logs/sms.log, which is how
 * local and test runs read back a code, exactly the way MAIL_MAILER=log works
 * for email. Wiring a real provider means adding one branch here and its
 * credentials to .env; nothing else needs to know.
 */
class Sms
{
    public static function send(string $toPhone, string $message): bool
    {
        $driver = strtolower(trim((string) Env::get('SMS_DRIVER', 'log')));

        if ($driver === 'log') {
            return self::sendViaLog($toPhone, $message);
        }

        error_log('[Keel] SMS failed: no provider is configured for SMS_DRIVER "' . $driver . '".');

        return false;
    }

    /**
     * Normalizes a typed number to E.164. US numbers are assumed when no
     * country code is given, which is the only market FairPlate serves today.
     * Returns null when the input cannot be a phone number.
     */
    public static function normalize(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with(trim($phone), '+')) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+' . $digits : null;
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        return null;
    }

    private static function sendViaLog(string $toPhone, string $message): bool
    {
        $logDirectory = dirname(__DIR__, 2) . '/storage/logs';
        $logPath = $logDirectory . '/sms.log';

        if (!is_dir($logDirectory) && !mkdir($logDirectory, 0775, true) && !is_dir($logDirectory)) {
            error_log('[Keel] SMS failed: could not create the log directory for SMS_DRIVER=log.');

            return false;
        }

        $entry = '[' . date('Y-m-d H:i:s') . "] SMS_DRIVER=log\n"
            . "To: {$toPhone}\n"
            . "\n"
            . $message
            . "\n"
            . str_repeat('-', 80)
            . "\n";

        if (@file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX) === false) {
            error_log('[Keel] SMS failed: could not write storage/logs/sms.log for SMS_DRIVER=log.');

            return false;
        }

        return true;
    }
}
