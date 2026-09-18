<?php

namespace Keel\App\Services\Pricing;

/**
 * Something in the money math is wrong, not merely unusual.
 *
 * Every throw site here is an invariant the spec states outright: cents that
 * do not balance, a rate that is not a rate, a capture that would exceed its
 * authorization. None of them are recoverable by retrying, so they surface as
 * an exception rather than a degraded number that quietly ships to a card.
 */
class PricingException extends \RuntimeException
{
}
