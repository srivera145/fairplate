<?php
/**
 * The run: one tap per step, and nothing else competing for the thumb.
 *
 * The current step is the whole top of the screen — a Navigate button and one
 * big action — and the steps behind it collapse into a line of times. A driver
 * double-parked with hazards on has one decision to make and this screen only
 * ever offers that one.
 *
 * Two of the spec's rules are visible here rather than merely enforced:
 *
 * The customer's address and phone do not render before pickup. Not hidden —
 * absent. Until the food is in the car the driver is going to a restaurant, and
 * there is nothing for them to do with either.
 *
 * The wait-pay counter appears only once the free minutes are gone. A number
 * ticking from zero for ten minutes would be a promise of nothing; a number
 * that appears when it starts costing is the truth about when the driver
 * started being paid to stand there.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Address;
use Keel\App\Models\Order;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;

$driveScripts = ['/js/drive.js'];
$order = $order ?? [];
$steps = $steps ?? [];
$address = $address ?? [];
$wait = $wait ?? null;
$pay = $pay ?? null;
$items = $items ?? [];
$showCustomer = (bool) ($showCustomer ?? false);
$requiresPhoto = (bool) ($requiresPhoto ?? false);
$isFinished = (bool) ($isFinished ?? false);
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$localTime = static function (?string $utc): string {
    if ($utc === null || $utc === '') {
        return '';
    }

    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('America/New_York'))
        ->format('g:ia');
};

$orderId = (int) $order['id'];
$current = null;

foreach ($steps as $step) {
    if ($step['current']) {
        $current = $step;
        break;
    }
}

$navigateUrl = $current === null
    ? null
    : ($current['navigate'] === 'restaurant' ? ($restaurantNavigateUrl ?? null) : ($customerNavigateUrl ?? null));

require __DIR__ . '/partials/top.php';
?>

<?php if ($isFinished): ?>
<section class="card">
    <div class="empty">
        <span class="empty-art"><?= Deck::icon('check-circle', 'icon icon-lg') ?></span>
        <h2 class="empty-title">Done</h2>
        <p class="text-muted">Order #<?= $orderId ?> is finished.</p>
        <a href="/drive" class="btn btn-primary">Back to driving</a>
    </div>
</section>

<?php else: ?>
<section class="card card-raised delivery-now">
    <div class="card-body stack stack-4">
        <p class="stat-label">Now</p>

        <?php if ($current !== null && $current['navigate'] === 'restaurant'): ?>
        <h2 class="delivery-where"><?= $escape((string) ($order['restaurant_name'] ?? 'Restaurant')) ?></h2>
        <p class="text-muted"><?= $escape((string) ($restaurantAddressLine ?? '')) ?></p>
        <?php elseif ($current !== null): ?>
        <?php
        // The address renders only on the drop-off steps, which is the spec's
        // rule stated as control flow rather than as a CSS class.
        ?>
        <h2 class="delivery-where"><?= $escape(Address::oneLine($address)) ?></h2>
        <?php if (trim((string) ($address['instructions'] ?? '')) !== ''): ?>
        <p class="delivery-instructions">
            <?= Deck::icon('info', 'icon icon-sm') ?>
            <?= $escape((string) $address['instructions']) ?>
        </p>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($navigateUrl !== null): ?>
        <a class="btn btn-lg delivery-navigate" href="<?= $escape($navigateUrl) ?>"
           target="_blank" rel="noopener">
            <?= Deck::icon('map-pin') ?>
            Navigate
        </a>
        <?php endif; ?>

        <?php if ($wait !== null && ($order['picked_up_at'] ?? null) === null): ?>
        <?php
        // Live, and hidden until the free minutes are used up. drive.js does the
        // showing; the terms come off the order's frozen snapshot, so the rate
        // counting up here is the rate the order was priced at.
        ?>
        <p class="delivery-wait"
           data-wait
           data-arrived-at="<?= $escape((string) $wait['arrived_at']) ?>"
           data-free-minutes="<?= (int) $wait['free_minutes'] ?>"
           data-per-min-cents="<?= (int) $wait['per_min_cents'] ?>"
           data-cap-cents="<?= (int) $wait['cap_cents'] ?>"
           hidden></p>
        <?php elseif ($wait !== null && (int) $wait['earned_cents'] > 0): ?>
        <p class="delivery-wait">
            <?= Deck::icon('clock', 'icon icon-sm') ?>
            Wait pay <?= $escape(Money::usd((int) $wait['earned_cents'])) ?>
            for <?= (int) $wait['minutes'] ?> min at the restaurant
        </p>
        <?php endif; ?>

        <?php if ($current !== null && $current['action'] !== 'delivered'): ?>
        <form method="POST" action="/drive/orders/<?= $orderId ?>/<?= $escape($current['action']) ?>">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-primary delivery-step-action">
                <?= $escape($current['cta']) ?>
            </button>
        </form>
        <?php elseif ($current !== null): ?>
        <form method="POST" action="/drive/orders/<?= $orderId ?>/delivered"
              enctype="multipart/form-data" class="stack stack-3">
            <?= Csrf::field() ?>
            <div class="field">
                <label class="label" for="photo">
                    <?= $requiresPhoto ? 'Photo of the drop-off (required)' : 'Photo of the drop-off (optional)' ?>
                </label>
                <?php if ($requiresPhoto): ?>
                <p class="help">
                    This customer asked for it to be left. A photo is the only record it arrived.
                </p>
                <?php endif; ?>
                <input class="file" type="file" id="photo" name="photo"
                       accept="image/*" capture="environment"
                       <?= $requiresPhoto ? 'required' : '' ?>>
            </div>
            <button type="submit" class="btn btn-primary delivery-step-action">Delivered</button>
        </form>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card-body stack stack-3">
        <ol class="stepper stepper-vertical">
            <?php foreach ($steps as $step): ?>
            <li class="step <?= $step['done'] ? 'is-done' : '' ?> <?= $step['current'] ? 'is-current' : '' ?>">
                <span class="step-marker"></span>
                <span class="step-label"><?= $escape($step['label']) ?></span>
                <?php if ($step['at'] !== null): ?>
                <span class="step-note"><?= $escape($localTime($step['at'])) ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-body stack stack-3">
        <h2 class="h6">Order #<?= $orderId ?></h2>
        <ul class="delivery-items" role="list">
            <?php foreach ($items as $item): ?>
            <li>
                <span class="fw-semi"><?= (int) $item['quantity'] ?>×</span>
                <?= $escape((string) $item['name_snapshot']) ?>
                <?php foreach (($item['options'] ?? []) as $option): ?>
                <span class="text-sm text-muted">· <?= $escape((string) $option['name_snapshot']) ?></span>
                <?php endforeach; ?>
            </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($pay !== null): ?>
        <p class="text-sm text-muted">
            Guaranteed <?= $escape(Money::usd((int) $pay['guaranteed_cents'])) ?>
            + tip <?= $escape(Money::usd((int) $pay['tip_cents'])) ?>
        </p>
        <?php endif; ?>

        <?php if ($showCustomer): ?>
        <?php
        // Rendered only after pickup, which is the point at which a driver has a
        // reason to contact the person waiting.
        ?>
        <div class="stack stack-1">
            <p class="fw-semi"><?= $escape(Order::customerFirstName($order)) ?></p>
            <?php if (trim((string) ($order['customer_phone'] ?? '')) !== ''): ?>
            <a class="btn btn-ghost" href="tel:<?= $escape((string) $order['customer_phone']) ?>">
                <?= Deck::icon('phone') ?>
                Call the customer
            </a>
            <?php endif; ?>
        </div>
        <?php elseif (trim((string) ($order['restaurant_phone'] ?? '')) !== ''): ?>
        <a class="btn btn-ghost" href="tel:<?= $escape((string) $order['restaurant_phone']) ?>">
            <?= Deck::icon('phone') ?>
            Call the restaurant
        </a>
        <?php endif; ?>
    </div>
</section>

<?php
// No offer URL: a driver mid-run is not being offered anything, so this page
// pings and nothing else. It pings on the active interval rather than the
// waiting one, because this is the stretch a customer is watching a map.
//
// The server refuses a ping from a driver who is not online whatever this says,
// and the script stops asking when it is told that, so an admin taking somebody
// offline mid-run does not need this page to have guessed.
?>
<div id="drive-config"
     hidden
     data-online="<?= $isFinished ? '0' : '1' ?>"
     data-offer-url=""
     data-location-url="/drive/location"
     data-offer-poll-seconds="<?= (int) ($offerPollSeconds ?? 3) ?>"
     data-ping-seconds="<?= (int) ($activePingSeconds ?? 10) ?>"></div>

<?php require __DIR__ . '/partials/bottom.php'; ?>
