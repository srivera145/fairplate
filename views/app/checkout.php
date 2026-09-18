<?php
/**
 * Checkout.
 *
 * The tip controls post a choice and get the whole breakdown back as markup.
 * They live outside the breakdown block on purpose: re-rendering them would
 * clear the custom amount somebody is halfway through typing.
 *
 * The Payment Element mounts into #payment-element. With no JavaScript there is
 * no card form — nothing can be done about that, Stripe's element is a script —
 * so the page says so plainly rather than showing a dead button.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Address;
use Keel\App\Models\Cart;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;

$appScripts = ['https://js.stripe.com/v3/', '/js/checkout.js'];
require __DIR__ . '/partials/top.php';

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$addresses = $addresses ?? [];
$restaurant = $summary['restaurant'] ?? null;
$tipMode = (string) ($tipMode ?? Cart::TIP_PERCENT);
$tipBasisPoints = (int) ($tipBasisPoints ?? Cart::DEFAULT_TIP_BASIS_POINTS);
?>

<section class="stack stack-1">
    <h1 class="h4">Checkout</h1>
    <?php if ($restaurant !== null): ?>
    <p class="text-sm text-muted">
        <?= $escape((string) $restaurant['name']) ?> ·
        <?= (int) ($summary['count'] ?? 0) ?> item<?= (int) ($summary['count'] ?? 0) === 1 ? '' : 's' ?>
        · <a href="/app/cart" class="link-quiet">Edit cart</a>
    </p>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-header"><h2 class="card-title">Deliver to</h2></div>
    <div class="card-body stack stack-3">
        <?php if ($address === null): ?>
        <p class="text-muted">You have no saved address yet.</p>
        <a href="/app/addresses" class="btn btn-primary">Add an address</a>
        <?php else: ?>
        <p class="fw-semi"><?= $escape((string) $address['label']) ?></p>
        <p class="text-sm text-muted"><?= $escape(Address::oneLine($address)) ?></p>
        <?php if (trim((string) ($address['instructions'] ?? '')) !== ''): ?>
        <p class="text-sm text-muted">“<?= $escape((string) $address['instructions']) ?>”</p>
        <?php endif; ?>

        <?php if (count($addresses) > 1): ?>
        <form method="POST" action="/app/cart/address" class="stack stack-2">
            <?= Csrf::field() ?>
            <input type="hidden" name="back" value="/app/checkout">
            <label class="sr-only" for="checkout-address">Deliver to</label>
            <select id="checkout-address" name="address_id" class="select">
                <?php foreach ($addresses as $option): ?>
                <option value="<?= (int) $option['id'] ?>"
                    <?= (int) $option['id'] === (int) $address['id'] ? 'selected' : '' ?>>
                    <?= $escape((string) $option['label']) ?> — <?= $escape(Address::oneLine($option)) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm">Change address</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card-header"><h2 class="card-title">Tip</h2></div>
    <div class="card-body stack stack-3">
        <p class="text-sm text-muted">100% of the tip goes to your driver.</p>

        <div class="tip-presets" role="group" aria-label="Tip">
            <?php foreach (Cart::TIP_PRESETS as $preset): ?>
            <button type="button"
                    class="btn <?= $tipMode === Cart::TIP_PERCENT && $tipBasisPoints === $preset ? 'btn-primary' : 'btn-outline' ?>"
                    data-tip-preset="<?= $preset ?>">
                <?= (int) ($preset / 100) ?>%
            </button>
            <?php endforeach; ?>
            <button type="button"
                    class="btn <?= $tipMode === Cart::TIP_CUSTOM ? 'btn-primary' : 'btn-outline' ?>"
                    data-tip-custom-toggle>
                Other
            </button>
        </div>

        <div class="field" data-tip-custom-field <?= $tipMode === Cart::TIP_CUSTOM ? '' : 'hidden' ?>>
            <label class="label" for="tip-dollars">Tip amount</label>
            <div class="input-group">
                <span class="addon">$</span>
                <input type="text" id="tip-dollars" class="input" inputmode="decimal"
                       value="<?= $escape(Money::toDollars((int) ($tipCents ?? 0))) ?>"
                       data-tip-custom-input>
            </div>
            <p class="help">Press Apply, or leave the field to update the total.</p>
            <button type="button" class="btn btn-sm mt-2" data-tip-custom-apply>Apply</button>
        </div>

        <noscript>
            <p class="help">Your tip is set to <?= (int) ($tipBasisPoints / 100) ?>%. Turn on JavaScript to change it.</p>
        </noscript>
    </div>
</section>

<section class="card">
    <div class="card-header"><h2 class="card-title">What you'll pay</h2></div>
    <div class="card-body" id="checkout-breakdown">
        <?php require __DIR__ . '/partials/breakdown.php'; ?>
    </div>
</section>

<section class="card">
    <div class="card-header"><h2 class="card-title">Payment</h2></div>
    <div class="card-body stack stack-3">
        <?php if (!$payable): ?>
        <?php
        // Deliberately not "fix the problems": a Stripe outage and a sold-out
        // item both land here, and only one of them is the customer's to fix.
        ?>
        <p class="text-muted">Payment opens once the problems above are resolved.</p>

        <?php elseif (($publishableKey ?? '') === '' || ($clientSecret ?? null) === null): ?>
        <div class="alert alert-warn">
            <?= Deck::icon('alert-triangle') ?>
            <p class="alert-body">Card payments are not configured on this environment. Your cart is saved.</p>
        </div>

        <?php else: ?>
        <div id="payment-element"
             data-checkout
             data-publishable-key="<?= $escape((string) $publishableKey) ?>"
             data-client-secret="<?= $escape((string) $clientSecret) ?>"
             data-return-url="/app/checkout/complete"
             data-quote-url="/app/checkout/quote"></div>

        <p class="text-sm text-bad" id="payment-error" role="alert" hidden></p>

        <div class="form-actions form-actions-sticky">
            <button type="button" class="btn btn-primary btn-lg btn-block" data-checkout-pay>
                Pay <span data-pay-total><?= $escape(Money::usd((int) $estimateTotal)) ?></span>
            </button>
        </div>

        <p class="text-sm text-muted">
            Your card is held, not charged, until the food is delivered. The hold is
            <?= $escape(Money::usd((int) $authorizedTotal)) ?>; the charge is
            <?= $escape(Money::usd((int) $estimateTotal)) ?> unless the restaurant is delayed.
        </p>

        <noscript>
            <div class="alert alert-warn">
                <p class="alert-body">Paying by card needs JavaScript. Your cart is saved until you come back.</p>
            </div>
        </noscript>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/partials/bottom.php'; ?>
