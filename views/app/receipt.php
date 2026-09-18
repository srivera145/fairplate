<?php
/**
 * What this order cost, and everything that happened to the money afterwards.
 *
 * Required by views/app/order.php rather than rendered on its own, because a
 * receipt is part of looking at an order rather than a separate page to
 * navigate to. Keeping it in its own file is what stops the order screen
 * becoming the only place four different money stories are told at once.
 *
 * Before the capture this is not a receipt and does not claim to be: the
 * heading says what is held, the total says the most the order can cost, and
 * the sentence underneath says nothing has been taken. After the capture the
 * same lines are what was charged, and the three sections below them are the
 * only things that may have moved since — a tip raised, money given back, and
 * what the card was left holding.
 *
 * The charge lines never change once settled. A tip raised afterwards is its
 * own charge and is shown as one, rather than being added into the tip line,
 * because the breakdown is what the card statement says and a receipt that
 * quietly disagrees with a statement is worse than one with two sections.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Order;
use Keel\App\Services\Pricing\Breakdown;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$localTime = static function (?string $utc): string {
    if ($utc === null || $utc === '') {
        return '';
    }

    $at = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));

    return $at === false ? '' : $at->setTimezone(new \DateTimeZone('America/New_York'))->format('j M, g:ia');
};

$orderId = (int) $order['id'];
$status = (string) $order['status'];
$settled = ($capturedCents ?? null) !== null;

// The settled charge lines if there are any, otherwise whatever the order was
// last priced at — which before delivery is the hold.
$shown = $settled ? ($finalBreakdown ?? $breakdown ?? null) : ($breakdown ?? null);
$tipAdjustments = $tipAdjustments ?? [];
$refunds = $refunds ?? [];
$tipWindow = $tipWindow ?? ['open' => false, 'hours' => 0];
?>

<?php if ($shown !== null): ?>
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><?= $settled ? 'Receipt' : 'What is held' ?></h2>
    </div>
    <div class="card-body stack stack-3">
        <dl class="breakdown">
            <?php foreach ($shown->displayLines() as $row): ?>
            <dt><?= $escape((string) $row['label']) ?></dt>
            <dd class="nums"><?= $escape(Money::usd((int) $row['cents'])) ?></dd>
            <?php endforeach; ?>
            <dt class="breakdown-total"><?= $settled ? 'Charged' : 'Held on your card' ?></dt>
            <dd class="breakdown-total nums">
                <?= $escape(Money::usd($settled ? (int) $capturedCents : (int) $shown->total())) ?>
            </dd>
        </dl>

        <?php if (!$settled && $status !== Order::STATUS_DELIVERED): ?>
        <p class="text-sm text-muted">
            Nothing is taken until the food arrives. The hold is the most this order can cost;
            the charge is usually less.
        </p>
        <?php elseif (!$settled): ?>
        <div class="alert alert-info">
            <?= Deck::icon('info') ?>
            <p class="alert-body">
                Delivered. The final charge lands in a moment — until then the figure above is
                what is held, and it is the most this order can ever cost.
            </p>
        </div>
        <?php endif; ?>

        <?php if ($settled && ($shown->line(Breakdown::WAIT_PAY)) > 0): ?>
        <p class="text-sm text-muted">
            Wait pay covers the time your driver spent waiting at the restaurant. It goes to them.
        </p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($tipAdjustments !== []): ?>
<section class="card">
    <div class="card-header"><h2 class="card-title">Extra tip</h2></div>
    <div class="card-body stack stack-3">
        <dl class="breakdown">
            <?php foreach ($tipAdjustments as $adjustment): ?>
            <dt>
                Tip added<?= $localTime((string) $adjustment['created_at']) === ''
                    ? '' : ' · ' . $escape($localTime((string) $adjustment['created_at'])) ?>
            </dt>
            <dd class="nums"><?= $escape(Money::usd((int) $adjustment['delta_cents'])) ?></dd>
            <?php if ((int) $adjustment['service_fee_cents'] > 0): ?>
            <dt>Service fee</dt>
            <dd class="nums"><?= $escape(Money::usd((int) $adjustment['service_fee_cents'])) ?></dd>
            <?php endif; ?>
            <?php endforeach; ?>
            <dt class="breakdown-total">Charged separately</dt>
            <dd class="breakdown-total nums">
                <?= $escape(Money::usd((int) ($tipAdjustmentChargedCents ?? 0))) ?>
            </dd>
        </dl>
        <p class="text-sm text-muted">Every cent of the tip goes to your driver.</p>
    </div>
</section>
<?php endif; ?>

<?php if ($refunds !== []): ?>
<section class="card">
    <div class="card-header"><h2 class="card-title">Refunds</h2></div>
    <div class="card-body stack stack-3">
        <dl class="breakdown">
            <?php foreach ($refunds as $refund): ?>
            <dt>
                <?= $escape(trim((string) ($refund['reason'] ?? '')) === ''
                    ? 'Refunded'
                    : (string) $refund['reason']) ?>
                <?php if ($localTime((string) $refund['created_at']) !== ''): ?>
                <span class="text-sm text-muted">· <?= $escape($localTime((string) $refund['created_at'])) ?></span>
                <?php endif; ?>
            </dt>
            <dd class="nums">-<?= $escape(Money::usd((int) $refund['amount_cents'])) ?></dd>
            <?php endforeach; ?>
            <dt class="breakdown-total">Refunded</dt>
            <dd class="breakdown-total nums">-<?= $escape(Money::usd((int) ($refundedCents ?? 0))) ?></dd>
        </dl>
        <p class="text-sm text-muted">
            Refunds go back to the card you paid with. Your bank usually shows them within a few days.
        </p>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($tipWindow['open'])): ?>
<section class="card">
    <div class="card-header"><h2 class="card-title">Add to your driver's tip</h2></div>
    <div class="card-body">
        <form method="POST" action="/app/orders/<?= $orderId ?>/tip" class="stack stack-3">
            <?= Csrf::field() ?>
            <div class="field">
                <label class="label" for="tip-amount">How much to add</label>
                <input type="text" id="tip-amount" name="amount" class="input"
                       inputmode="decimal" autocomplete="off" placeholder="3.00" required>
            </div>
            <p class="text-sm text-muted">
                Charged to the same card, on its own. It goes 100% to your driver.
                You can add to the tip for <?= (int) $tipWindow['hours'] ?> hours after delivery.
            </p>
            <button type="submit" class="btn btn-lg btn-block">
                <?= Deck::icon('heart') ?> Add to tip
            </button>
        </form>
    </div>
</section>
<?php endif; ?>
