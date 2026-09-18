<?php
/**
 * The driver's home screen.
 *
 * Four states below, one at a time — not signed up, waiting on approval,
 * offline, online and waiting — and an offer that covers all of them when one
 * arrives. Whichever is true takes the screen, because a driver glancing at a
 * phone in a cup holder should never have to work out which part of the page is
 * about them.
 *
 * The offer lives in a slot the server fills on load and the poll refills every
 * three seconds. One slot rather than two paths means the card that arrives
 * with the page and the card that arrives thirty seconds later are the same
 * markup, and it cannot end up on screen twice.
 *
 * The online switch is a form with one button rather than a checkbox. A
 * checkbox that needs a script to submit is a switch that silently does nothing
 * on a phone whose connection dropped mid-load, and this is the control the
 * whole shift hangs off.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Driver;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;
use Keel\Core\View;

$driveScripts = ['/js/drive.js'];
$driver = $driver ?? null;
$card = $card ?? null;
$activeOrder = $activeOrder ?? null;
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$isApproved = Driver::isApproved($driver);
$isOnline = $isApproved && Driver::isOnline($driver);
$stripeConnected = trim((string) ($driver['stripe_account_id'] ?? '')) !== '';

require __DIR__ . '/partials/top.php';
?>

<?php if ($driver === null): ?>
<section class="card">
    <div class="empty">
        <span class="empty-art"><?= Deck::icon('car', 'icon icon-lg') ?></span>
        <h2 class="empty-title">Drive with FairPlate</h2>
        <p>
            Every offer shows what it pays and both distances before you accept, and the
            guarantee never drops once you have. Tips are yours in full.
        </p>
        <a href="/drive/onboarding" class="btn btn-primary btn-lg">Get set up</a>
    </div>
</section>

<?php elseif (!$isApproved): ?>
<section class="card">
    <div class="card-body stack stack-4">
        <div class="bar">
            <h2 class="h5">Pending approval</h2>
            <span class="badge badge-warn push">Waiting</span>
        </div>
        <p class="text-muted">
            Your application is with FairPlate. Nothing else is needed from you unless
            something below is still open.
        </p>
        <ul class="drive-checklist" role="list">
            <li class="drive-check <?= Driver::hasVehicle($driver) ? 'is-done' : '' ?>">
                <?= Deck::icon(Driver::hasVehicle($driver) ? 'check-circle' : 'alert-circle', 'icon icon-sm') ?>
                Profile and vehicle
            </li>
            <li class="drive-check <?= $stripeConnected ? 'is-done' : '' ?>">
                <?= Deck::icon($stripeConnected ? 'check-circle' : 'alert-circle', 'icon icon-sm') ?>
                Payouts connected
            </li>
            <li class="drive-check">
                <?= Deck::icon('clock', 'icon icon-sm') ?>
                FairPlate review
            </li>
        </ul>
        <a href="/drive/onboarding" class="btn">Review your details</a>
    </div>
</section>

<?php else: ?>
<section class="card drive-today">
    <div class="card-body bar gap-3">
        <div class="stack stack-0">
            <p class="stat-label">Today</p>
            <p class="drive-today-value"><?= $escape(Money::usd((int) ($todayCents ?? 0))) ?></p>
        </div>
        <a href="/drive/earnings" class="btn btn-ghost push">
            <?= (int) ($todayCount ?? 0) ?> <?= (int) ($todayCount ?? 0) === 1 ? 'delivery' : 'deliveries' ?>
            <?= Deck::icon('chevron-right', 'icon icon-sm') ?>
        </a>
    </div>
</section>

<?php if ($activeOrder !== null): ?>
<section class="card card-raised">
    <div class="card-body stack stack-3">
        <h2 class="h5">You are on a delivery</h2>
        <p class="text-muted">Order #<?= (int) $activeOrder['id'] ?> is still open.</p>
        <a href="/drive/orders/<?= (int) $activeOrder['id'] ?>" class="btn btn-primary drive-primary-action">
            Back to the delivery
        </a>
    </div>
</section>

<?php else: ?>
<form method="POST" action="/drive/online" class="drive-toggle-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="online" value="<?= $isOnline ? '0' : '1' ?>">
    <button type="submit" class="drive-toggle <?= $isOnline ? 'is-online' : '' ?>">
        <span class="drive-toggle-state"><?= $isOnline ? 'Online' : 'Offline' ?></span>
        <span class="drive-toggle-action"><?= $isOnline ? 'Tap to go offline' : 'Tap to go online' ?></span>
    </button>
</form>

<?php if ($isOnline): ?>
<section class="card">
    <div class="empty">
        <span class="empty-art"><?= Deck::icon('search', 'icon icon-lg') ?></span>
        <h2 class="empty-title">Waiting for offers</h2>
        <p class="text-muted">Keep this screen open. An offer will take over the screen when one arrives.</p>
        <p class="text-sm text-faint" data-location-note hidden></p>
    </div>
</section>
<?php else: ?>
<section class="card">
    <div class="card-body stack stack-2">
        <p class="text-muted">
            You will not be sent offers, and FairPlate is not recording where you are.
            Both start when you go online.
        </p>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php
// Everything the script needs, as data attributes rather than PHP inlined into
// a <script> block: where to poll, where to send positions, and how often.
?>
<div id="drive-config"
     hidden
     data-online="<?= $isOnline ? '1' : '0' ?>"
     data-offer-url="/drive/offers/current"
     data-location-url="/drive/location"
     data-offer-poll-seconds="<?= (int) ($offerPollSeconds ?? 3) ?>"
     data-ping-seconds="<?= (int) ($onlinePingSeconds ?? 15) ?>"></div>

<div class="offer-slot" data-offer-slot<?= $card === null ? ' hidden' : '' ?>>
    <?php if ($card !== null) { View::render('drive.partials.offer', $card); } ?>
</div>

<?php require __DIR__ . '/partials/bottom.php'; ?>
