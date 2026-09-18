<?php
/**
 * One order: where it is, what is in it, and what it cost.
 *
 * The map is only here while the driver is carrying this customer's food, and
 * the endpoint behind it enforces that independently — the page not asking is
 * a courtesy, the server refusing is the rule.
 *
 * What the order cost — held, charged, tipped again or refunded — is
 * receipt.php's, required below. It is four stories that only make sense
 * together, and keeping them in their own file is what stops this page becoming
 * the place all four are told at once.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Address;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;

$appScripts = ['/js/tracking.js'];
require __DIR__ . '/partials/top.php';

$items = $items ?? [];
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$orderId = (int) $order['id'];
$addressLine = Address::oneLine($address ?? []);
?>

<section class="stack stack-1">
    <div class="bar gap-2 wrap">
        <h1 class="h4"><?= $escape((string) ($restaurant['name'] ?? 'Your order')) ?></h1>
        <span class="badge push">#<?= $orderId ?></span>
    </div>
    <p class="text-sm text-muted"><?= $escape($addressLine) ?></p>
</section>

<section class="card">
    <div class="card-body"
         id="tracking"
         data-order-id="<?= $orderId ?>"
         data-status-url="/app/orders/<?= $orderId ?>/status"
         data-location-url="/app/orders/<?= $orderId ?>/driver-location"
         data-poll-seconds="<?= (int) $pollSeconds ?>"
         data-trackable="<?= !empty($trackable) ? '1' : '0' ?>">
        <?php require __DIR__ . '/partials/tracking.php'; ?>
    </div>
</section>

<?php if (!empty($trackable)): ?>
<section class="card" id="driver-map-card">
    <div class="card-header"><h2 class="card-title">Where your driver is</h2></div>
    <div class="card-body stack stack-2">
        <div id="driver-map"
             class="tracking-map"
             data-maps-key="<?= $escape((string) $mapsKey) ?>"
             data-drop-lat="<?= $escape((string) ($address['lat'] ?? '')) ?>"
             data-drop-lng="<?= $escape((string) ($address['lng'] ?? '')) ?>"
             role="img"
             aria-label="Map of your driver's position"></div>
        <p class="text-sm text-muted" data-driver-status>Waiting for your driver's first position…</p>
    </div>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-header"><h2 class="card-title">Your order</h2></div>
    <div class="card-body">
        <ul class="stack stack-3">
            <?php foreach ($items as $line): ?>
            <li class="bar gap-3 items-start">
                <span class="stack stack-1">
                    <span class="fw-semi">
                        <?= (int) $line['quantity'] ?>&times; <?= $escape((string) $line['name_snapshot']) ?>
                    </span>
                    <?php if (($line['options'] ?? []) !== []): ?>
                    <span class="text-sm text-muted">
                        <?php
                        $labels = array_map(
                            static fn (array $option): string => (string) $option['name_snapshot'],
                            $line['options']
                        );
                        ?>
                        <?= $escape(implode(', ', $labels)) ?>
                    </span>
                    <?php endif; ?>
                    <?php if (trim((string) ($line['notes'] ?? '')) !== ''): ?>
                    <span class="text-sm text-muted">“<?= $escape((string) $line['notes']) ?>”</span>
                    <?php endif; ?>
                </span>
                <span class="nums push"><?= $escape(Money::usd((int) $line['line_total_cents'])) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<?php require __DIR__ . '/receipt.php'; ?>

<form method="POST" action="/app/orders/<?= $orderId ?>/reorder" class="form-actions">
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn-lg btn-block"><?= Deck::icon('refresh') ?> Order this again</button>
</form>

<?php require __DIR__ . '/partials/bottom.php'; ?>
