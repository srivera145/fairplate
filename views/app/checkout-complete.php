<?php
/**
 * The waiting room between paying and having an order.
 *
 * The card is held, the webhook is on its way, and this page has nothing to do
 * but wait for it. That is deliberate: the order is created by
 * payment_intent.amount_capturable_updated and not by this request, so a
 * customer who closes the tab here still gets their dinner.
 *
 * The wait is a meta refresh rather than a script, so it works with JavaScript
 * off, and it counts how long it has waited so the page can stop refreshing and
 * offer a way out instead of spinning forever.
 */

use EchoDial\Deck\Deck;
use Keel\App\Services\Pricing\Money;

$waited = (int) ($waited ?? 0);
$giveUp = (bool) ($giveUp ?? false);
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$next = '/app/checkout/complete?payment_intent=' . rawurlencode((string) $paymentIntentId)
    . '&waited=' . ($waited + 2);
?>
<?php if (!$giveUp): ?>
<?php
// The refresh has to be in <head>, so it is emitted before top.php closes it.
$appHeadExtra = '<meta http-equiv="refresh" content="2; url=' . htmlspecialchars($next, ENT_QUOTES, 'UTF-8') . '">';
?>
<?php endif; ?>
<?php require __DIR__ . '/partials/top.php'; ?>

<section class="card">
    <div class="empty">
        <?php if ($giveUp): ?>
        <span class="empty-art"><?= Deck::icon('clock', 'icon icon-lg') ?></span>
        <h1 class="empty-title">Still confirming</h1>
        <p class="text-muted">
            Your card is held for <?= $escape(Money::usd((int) $authorizedCents)) ?> and your payment went
            through. The order is taking longer than usual to appear — it will show up in your
            orders on its own, and nothing is charged until it is delivered.
        </p>
        <a href="/app/orders" class="btn btn-primary btn-lg">Go to your orders</a>
        <a href="<?= $escape($next) ?>" class="btn">Check again</a>

        <?php else: ?>
        <span class="empty-art"><span class="spinner spinner-lg"></span></span>
        <h1 class="empty-title">Confirming your order</h1>
        <p class="text-muted">
            Your card is held for <?= $escape(Money::usd((int) $authorizedCents)) ?>. We are waiting for
            the payment to confirm — this page updates itself.
        </p>
        <a href="<?= $escape($next) ?>" class="btn">Check now</a>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/partials/bottom.php'; ?>
