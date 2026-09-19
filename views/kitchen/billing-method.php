<?php
/**
 * The card or bank account the monthly fee is charged to.
 *
 * A Stripe Payment Element against a SetupIntent, so the form, the bank
 * selection and the ACH mandate wording are all Stripe's and none of them has
 * to stay compliant here. Confirming redirects to /kitchen/billing/method/
 * complete, which re-reads the intent rather than believing the redirect.
 *
 * Nothing is charged on this page. Saying so plainly matters: an owner typing a
 * card into a screen headed "Billing" reasonably expects to be billed, and the
 * first invoice is weeks away.
 */

use EchoDial\Deck\Deck;

$kitchenScripts = ['https://js.stripe.com/v3/', '/js/kitchen-billing.js'];

require __DIR__ . '/partials/top.php';

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>

<section class="card">
    <div class="card-header">
        <h2 class="card-title">Add a payment method</h2>
    </div>
    <div class="card-body stack stack-4">
        <?php if (($publishableKey ?? '') === '' || ($clientSecret ?? '') === ''): ?>
        <div class="alert alert-warn">
            <?= Deck::icon('alert-triangle') ?>
            <p class="alert-body">Billing is not configured on this environment yet.</p>
        </div>
        <?php else: ?>
        <p>
            This is saved for your monthly fee and charged on the 1st. Nothing is charged today, and
            nothing is ever taken out of an order.
        </p>

        <div id="billing-payment-element"
             data-billing-method
             data-publishable-key="<?= $escape((string) $publishableKey) ?>"
             data-client-secret="<?= $escape((string) $clientSecret) ?>"
             data-return-url="/kitchen/billing/method/complete"></div>

        <p class="text-sm text-bad" id="billing-error" role="alert" hidden></p>

        <div class="form-actions">
            <button type="button" class="btn btn-primary" data-billing-save>Save payment method</button>
            <a href="/kitchen/billing" class="btn btn-ghost">Cancel</a>
        </div>

        <noscript>
            <div class="alert alert-warn">
                <p class="alert-body">
                    Saving a payment method needs JavaScript. Call us and we will set it up with you.
                </p>
            </div>
        </noscript>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/partials/bottom.php'; ?>
