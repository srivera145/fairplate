<?php

namespace Keel\App\Jobs;

use Keel\App\Services\Dispatch\DispatchService;

/**
 * Close an offer nobody answered, and move on to the next driver.
 *
 * Queued alongside every offer with the offer's own timeout as its delay, so
 * the countdown on the driver's screen and the clock that actually ends the
 * round are the same number.
 *
 * It is safe to run late, early or twice. Late is the normal case and does the
 * work; early puts itself back for the remaining seconds rather than cutting a
 * driver's decision short; twice finds the offer already answered and returns.
 */
class OfferTimeoutJob implements Job
{
    public function handle(array $data): void
    {
        $offerId = (int) ($data['offer_id'] ?? 0);

        if ($offerId <= 0) {
            throw new \RuntimeException('OfferTimeoutJob needs an offer_id.');
        }

        (new DispatchService())->timeout($offerId);
    }
}
