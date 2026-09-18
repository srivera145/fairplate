<?php

namespace Keel\App\Models;

use Keel\Core\Database;

/**
 * One row per webhook event this application has seen.
 *
 * Stripe retries on any response it does not get quickly enough, so the same
 * event arrives more than once as a matter of course. claim() inserts the id and
 * reports whether the insert was new; a handler that gets false has already run
 * and returns 200 without doing the work twice.
 *
 * INSERT IGNORE rather than a SELECT-then-INSERT: two deliveries can be in
 * flight at the same moment, and only the unique index settles that race.
 */
class WebhookEvent extends Model
{
    protected const TABLE = 'webhook_events';

    protected const COLUMNS = ['provider', 'event_id', 'type', 'handled_at'];

    public const PROVIDER_STRIPE = 'stripe';

    /**
     * True when this caller is the first to see this event.
     */
    public static function claim(string $eventId, string $type, string $provider = self::PROVIDER_STRIPE): bool
    {
        if (trim($eventId) === '') {
            return false;
        }

        $statement = Database::connection()->prepare(
            'INSERT IGNORE INTO webhook_events (provider, event_id, type) VALUES (?, ?, ?)'
        );
        $statement->execute([$provider, $eventId, $type]);

        return $statement->rowCount() === 1;
    }

    public static function markHandled(string $eventId, string $provider = self::PROVIDER_STRIPE): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE webhook_events SET handled_at = UTC_TIMESTAMP() WHERE provider = ? AND event_id = ?'
        );
        $statement->execute([$provider, $eventId]);
    }

    public static function seen(string $eventId, string $provider = self::PROVIDER_STRIPE): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM webhook_events WHERE provider = ? AND event_id = ? LIMIT 1'
        );
        $statement->execute([$provider, $eventId]);

        return (bool) $statement->fetchColumn();
    }
}
