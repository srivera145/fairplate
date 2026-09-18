<?php
/**
 * The offer card.
 *
 * Rendered with the home screen when an offer is already waiting, and again by
 * the three-second poll, so there is one description of an offer and it lives
 * here rather than in a template string inside a script.
 *
 * The order things appear in is the order a driver reads them in, and it is the
 * order they decide in: what the job pays, how far for that money, where it is,
 * and then the two buttons. The payout is first and it is enormous, because it
 * is the only number being compared against the card on the other app.
 *
 * Every figure comes from PricingService via Dispatch\OfferCard, computed from
 * the breakdown frozen on the order at checkout. The spec's promise is that a
 * guarantee never drops after acceptance; the way that is kept is that this
 * card is arithmetic on what the customer has already been charged.
 *
 * Nothing here says who the customer is. A driver deciding whether to take a
 * job does not need a name or an address, and an offer that is declined should
 * leave nothing behind.
 */

use EchoDial\Deck\Deck;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;

$offer = $offer ?? [];
$pay = $pay ?? [];
$secondsLeft = (int) ($seconds_left ?? 0);
$windowSeconds = max(1, (int) ($window_seconds ?? 1));
$toRestaurant = $to_restaurant_miles ?? null;
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$miles = static fn (float $value): string => rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') . ' mi';
?>
<section class="offer-card card card-raised"
         data-offer
         data-offer-id="<?= (int) ($offer['id'] ?? 0) ?>"
         data-seconds-left="<?= $secondsLeft ?>"
         data-window-seconds="<?= $windowSeconds ?>"
         aria-labelledby="offer-payout">

    <header class="offer-head bar gap-3">
        <div class="stack stack-0 min-w-0">
            <p class="offer-eyebrow">Guaranteed</p>
            <p class="offer-payout" id="offer-payout" data-offer-payout>
                <?= $escape(Money::usd((int) $pay['payout_cents'])) ?>
            </p>
        </div>

        <span class="ring-wrap push" data-countdown>
            <span class="ring offer-ring"
                  style="--value: 100; --size: 64px; --thickness: 7px"
                  role="timer"
                  aria-label="Time left to answer"
                  data-countdown-ring></span>
            <span class="ring-label" data-countdown-label><?= $secondsLeft ?></span>
        </span>
    </header>

    <div class="card-body stack stack-4">
        <p class="offer-rate">
            <?= $escape(Money::usd((int) $pay['per_mile_cents'])) ?>/mi
            <span class="text-muted">·</span>
            <?= $escape($miles((float) ($dropoff_miles ?? 0.0))) ?> to the door
        </p>

        <dl class="offer-lines">
            <div class="offer-line">
                <dt>Base</dt>
                <dd><?= $escape(Money::usd((int) $pay['base_cents'])) ?></dd>
            </div>
            <div class="offer-line">
                <dt><?= $escape($miles((float) $pay['route_miles'])) ?> at the mileage rate</dt>
                <dd><?= $escape(Money::usd((int) $pay['mileage_cents'])) ?></dd>
            </div>
            <?php
            // Base plus mileage need not come to the guarantee: the minimum
            // payout is what makes a half-mile run worth taking, and a card that
            // showed two numbers that did not add up would be the first thing a
            // driver asked about.
            $built = (int) $pay['base_cents'] + (int) $pay['mileage_cents'];
            $topUp = (int) $pay['guaranteed_cents'] - $built;
            ?>
            <?php if ($topUp > 0): ?>
            <div class="offer-line">
                <dt>Minimum payout tops this up by</dt>
                <dd><?= $escape(Money::usd($topUp)) ?></dd>
            </div>
            <?php endif; ?>
            <div class="offer-line">
                <dt>Tip <span class="text-muted">(yours, in full)</span></dt>
                <dd><?= $escape(Money::usd((int) $pay['tip_cents'])) ?></dd>
            </div>
        </dl>

        <p class="offer-wait text-sm text-muted">
            <?= Deck::icon('clock', 'icon icon-sm') ?>
            + <?= $escape(Money::usd((int) $pay['wait_per_min_cents'])) ?>/min wait pay after
            <?= (int) $pay['wait_free_minutes'] ?> min at the restaurant.
        </p>

        <div class="offer-ends stack stack-2">
            <p class="offer-end">
                <?= Deck::icon('receipt', 'icon icon-sm') ?>
                <span class="fw-semi"><?= $escape((string) ($restaurant_name ?? 'Restaurant')) ?></span>
                <?php if ($toRestaurant !== null): ?>
                <span class="text-muted">· <?= $escape($miles((float) $toRestaurant)) ?> away</span>
                <?php endif; ?>
            </p>
            <p class="offer-end text-muted">
                <?= Deck::icon('map-pin', 'icon icon-sm') ?>
                <?= $escape($miles((float) ($dropoff_miles ?? 0.0))) ?> from there to the customer
            </p>
        </div>
    </div>

    <?php
    // Two real forms, not one form with two submit buttons and not a script.
    // A phone that failed to load drive.js still has an Accept that works; what
    // it loses is the ring counting down, which is decoration over a deadline
    // the server enforces anyway.
    ?>
    <div class="offer-actions">
        <form method="POST" action="/drive/offers/<?= (int) ($offer['id'] ?? 0) ?>/accept">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-primary offer-accept">Accept</button>
        </form>
        <form method="POST" action="/drive/offers/<?= (int) ($offer['id'] ?? 0) ?>/decline">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-ghost offer-decline">Decline</button>
        </form>
    </div>
</section>
