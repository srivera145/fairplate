<?php
/**
 * One order.
 *
 * Expects $order (a row from Order::boardForRestaurant with an 'items' key).
 *
 * The two action disclosures are plain forms. The <details> id is stable across
 * a redraw, which is what lets the poll reopen whichever one was showing after
 * it swaps the board.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Order;
use Keel\App\Services\OrderLifecycle;
use Keel\Core\Csrf;

$orderId = (int) $order['id'];
$status = (string) $order['status'];
$isNew = $status === Order::STATUS_PLACED;
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$placedAt = $order['placed_at'] ?? $order['created_at'] ?? null;
$waiting = $placedAt === null
    ? null
    : max(0, (int) floor((time() - strtotime((string) $placedAt . ' UTC')) / 60));
?>
<article class="kanban-card order-card <?= $isNew ? 'order-card-new' : '' ?>"
         <?= $isNew ? 'data-new-order-id="' . $orderId . '"' : '' ?>>
    <div class="bar gap-2">
        <span class="order-card-num">#<?= $orderId ?></span>
        <span class="badge push"><?= $escape(Order::customerFirstName($order)) ?></span>
    </div>

    <p class="kanban-card-meta">
        <?php if ($waiting !== null): ?>
        <?= Deck::icon('clock', 'icon icon-sm') ?> <?= $waiting ?> min ago
        <?php endif; ?>
        <?php if ($order['prep_minutes'] !== null): ?>
        · <?= (int) $order['prep_minutes'] ?> min prep
        <?php endif; ?>
    </p>

    <dl class="order-lines">
        <?php foreach ($order['items'] ?? [] as $line): ?>
        <dt><?= (int) $line['quantity'] ?>&times;</dt>
        <dd>
            <?= $escape((string) $line['name_snapshot']) ?>
            <?php if (($line['options'] ?? []) !== []): ?>
            <span class="order-line-opts">
                <?php $labels = [];
                foreach ($line['options'] as $option) {
                    $labels[] = (string) $option['name_snapshot'];
                } ?>
                <?= $escape(implode(', ', $labels)) ?>
            </span>
            <?php endif; ?>
            <?php if (trim((string) ($line['notes'] ?? '')) !== ''): ?>
            <span class="order-line-opts">“<?= $escape((string) $line['notes']) ?>”</span>
            <?php endif; ?>
        </dd>
        <?php endforeach; ?>
    </dl>

    <?php if ($order['driver_name'] !== null): ?>
    <p class="text-sm">
        <?= Deck::icon('truck', 'icon icon-sm') ?>
        <strong><?= $escape((string) $order['driver_name']) ?></strong>
        <?php if ($order['driver_eta_at'] !== null): ?>
        · ETA <?= $escape(
            (new DateTimeImmutable((string) $order['driver_eta_at'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('America/New_York'))
                ->format('g:ia')
        ) ?>
        <?php else: ?>
        · ETA pending
        <?php endif; ?>
    </p>
    <?php endif; ?>

    <?php if ($status === Order::STATUS_PLACED): ?>
    <div class="order-actions">
        <details class="order-disclosure" id="accept-<?= $orderId ?>">
            <summary class="btn btn-primary btn-lg btn-block">Accept</summary>
            <form method="POST" action="/kitchen/orders/<?= $orderId ?>/accept" class="order-choices">
                <?= Csrf::field() ?>
                <?php foreach (OrderLifecycle::PREP_MINUTES as $minutes): ?>
                <button type="submit" name="prep_minutes" value="<?= $minutes ?>" class="btn btn-outline btn-lg">
                    <?= $minutes ?> min
                </button>
                <?php endforeach; ?>
            </form>
        </details>

        <details class="order-disclosure" id="reject-<?= $orderId ?>">
            <summary class="btn btn-lg btn-block">Reject</summary>
            <form method="POST" action="/kitchen/orders/<?= $orderId ?>/reject" class="order-choices">
                <?= Csrf::field() ?>
                <?php foreach (OrderLifecycle::REJECT_REASONS as $key => $label): ?>
                <button type="submit" name="reason" value="<?= $escape($key) ?>" class="btn btn-danger">
                    <?= $escape($label) ?>
                </button>
                <?php endforeach; ?>
            </form>
        </details>
    </div>
    <?php elseif ($status === Order::STATUS_ACCEPTED): ?>
    <form method="POST" action="/kitchen/orders/<?= $orderId ?>/ready">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-primary btn-lg btn-block">Mark ready</button>
    </form>
    <?php else: ?>
    <p class="text-sm text-muted">
        <?= $status === Order::STATUS_DRIVER_ASSIGNED ? 'Waiting for the driver.' : 'Waiting for a driver.' ?>
    </p>
    <?php endif; ?>
</article>
