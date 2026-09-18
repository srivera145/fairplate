<?php
/**
 * Signing up to drive, and the account screen afterwards.
 *
 * One form and one panel, in the order somebody would reasonably do them. Name
 * and car first: it is two minutes and it is what a customer will look for in
 * the street. Bank details second, through Stripe, because handing a tax
 * identifier to a company you have not finished signing up to is a fair thing
 * to hesitate over — and because FairPlate never sees any of it.
 *
 * The status line is deliberately specific about who is holding what. "Pending"
 * on its own reads as a spinner; a driver who has done their part and is
 * waiting on FairPlate should be told that is what is happening.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Driver;
use Keel\Core\Csrf;

$driver = $driver ?? null;
$values = $values ?? [];
$connect = $connect ?? [];
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$isApproved = Driver::isApproved($driver);
$hasProfile = $driver !== null;
$connected = (bool) ($connect['connected'] ?? false);
$detailsSubmitted = (bool) ($connect['details_submitted'] ?? false);
$payoutsEnabled = (bool) ($connect['payouts_enabled'] ?? false);

require __DIR__ . '/partials/top.php';
?>

<section class="card">
    <div class="card-body stack stack-4">
        <div class="bar">
            <h2 class="h5">Status</h2>
            <span class="badge <?= $isApproved ? 'badge-good' : 'badge-warn' ?> push">
                <?= $isApproved ? 'Approved' : 'Pending approval' ?>
            </span>
        </div>
        <p class="text-muted">
            <?php if ($isApproved): ?>
            You are cleared to take offers. Go online on the home screen whenever you want work.
            <?php elseif (!$hasProfile): ?>
            Fill in the form below to apply. It takes about two minutes.
            <?php elseif (!$detailsSubmitted): ?>
            One thing left on your side: connect payouts, so FairPlate can pay you.
            <?php else: ?>
            Everything on your side is done. FairPlate reviews new drivers by hand, and you
            will be able to go online as soon as that is finished.
            <?php endif; ?>
        </p>
    </div>
</section>

<section class="card">
    <form method="POST" action="/drive/onboarding" class="card-body stack stack-4">
        <?= Csrf::field() ?>
        <h2 class="h5">You and your vehicle</h2>
        <p class="text-sm text-muted">
            Customers are shown your first name and your car so they can spot you in the
            street. Nothing else about you is shared with them.
        </p>

        <div class="field">
            <label class="label" for="name">Your name</label>
            <input class="input" type="text" id="name" name="name" maxlength="80" required
                   autocomplete="name" value="<?= $escape((string) ($values['name'] ?? '')) ?>">
        </div>

        <div class="field">
            <label class="label" for="vehicle_make">Make</label>
            <input class="input" type="text" id="vehicle_make" name="vehicle_make" maxlength="80" required
                   placeholder="Toyota" value="<?= $escape((string) ($values['vehicle_make'] ?? '')) ?>">
        </div>

        <div class="field">
            <label class="label" for="vehicle_model">Model</label>
            <input class="input" type="text" id="vehicle_model" name="vehicle_model" maxlength="80" required
                   placeholder="Corolla" value="<?= $escape((string) ($values['vehicle_model'] ?? '')) ?>">
        </div>

        <div class="field">
            <label class="label" for="vehicle_color">Colour</label>
            <input class="input" type="text" id="vehicle_color" name="vehicle_color" maxlength="40"
                   placeholder="Silver" value="<?= $escape((string) ($values['vehicle_color'] ?? '')) ?>">
        </div>

        <div class="field">
            <label class="label" for="plate">Plate</label>
            <input class="input" type="text" id="plate" name="plate" maxlength="20"
                   autocapitalize="characters" placeholder="LEON421"
                   value="<?= $escape((string) ($values['plate'] ?? '')) ?>">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary drive-primary-action">
                <?= $hasProfile ? 'Save' : 'Apply to drive' ?>
            </button>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-body stack stack-4">
        <div class="bar">
            <h2 class="h5">Payouts</h2>
            <?php if ($payoutsEnabled): ?>
            <span class="badge badge-good push">Ready</span>
            <?php elseif ($detailsSubmitted): ?>
            <span class="badge badge-warn push">Stripe is checking</span>
            <?php elseif ($connected): ?>
            <span class="badge push">Started</span>
            <?php endif; ?>
        </div>

        <p class="text-sm text-muted">
            FairPlate pays through Stripe. Stripe collects your bank details and tax
            information directly and FairPlate never sees them.
        </p>

        <?php if (!$hasProfile): ?>
        <p class="text-muted">Save your details above first.</p>
        <?php else: ?>
        <?php if (!empty($connect['requirements'])): ?>
        <div class="alert alert-warn">
            <?= Deck::icon('alert-triangle') ?>
            <div class="alert-body">
                <p class="alert-title">Stripe still needs a few things</p>
                <p class="text-sm">Opening payouts again will take you to exactly what is missing.</p>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" action="/drive/connect/start">
            <?= Csrf::field() ?>
            <button type="submit" class="btn <?= $payoutsEnabled ? '' : 'btn-primary' ?> drive-primary-action">
                <?= Deck::icon('credit-card') ?>
                <?= $connected ? 'Open payouts settings' : 'Connect payouts' ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card-body stack stack-3">
        <h2 class="h5">Account</h2>
        <form method="POST" action="/logout">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-ghost">
                <?= Deck::icon('log-out') ?>
                Sign out
            </button>
        </form>
    </div>
</section>

<?php require __DIR__ . '/partials/bottom.php'; ?>
