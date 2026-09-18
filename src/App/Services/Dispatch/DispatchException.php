<?php

namespace Keel\App\Services\Dispatch;

/**
 * A dispatch action that could not happen, and why.
 *
 * Every one of these is a race rather than a bug: two drivers tapping Accept on
 * the same order, a tap that landed after the countdown ran out, a decline on an
 * offer the timeout job already closed. The driver app turns each into a
 * sentence and puts the driver back on the home screen, so the reason is part of
 * the exception rather than something the controller has to infer from a
 * message string.
 */
class DispatchException extends \RuntimeException
{
    /** Somebody else got there first. */
    public const REASON_TAKEN = 'taken';

    /** The countdown ran out before the tap landed. */
    public const REASON_EXPIRED = 'expired';

    /** This offer has already been answered. */
    public const REASON_ANSWERED = 'answered';

    /** This offer is not this driver's to answer. */
    public const REASON_NOT_YOURS = 'not_yours';

    /** The driver is not in a state to take work. */
    public const REASON_UNAVAILABLE = 'unavailable';

    /** The order has moved on, or is no longer there. */
    public const REASON_GONE = 'gone';

    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function taken(): self
    {
        return new self('Another driver took that order.', self::REASON_TAKEN);
    }

    public static function expired(): self
    {
        return new self('That offer ran out.', self::REASON_EXPIRED);
    }

    public static function answered(): self
    {
        return new self('That offer has already been answered.', self::REASON_ANSWERED);
    }

    public static function notYours(): self
    {
        return new self('That offer was not sent to you.', self::REASON_NOT_YOURS);
    }

    public static function unavailable(string $message): self
    {
        return new self($message, self::REASON_UNAVAILABLE);
    }

    public static function gone(): self
    {
        return new self('That order is no longer waiting for a driver.', self::REASON_GONE);
    }
}
