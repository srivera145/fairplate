<?php
/**
 * Order history.
 *
 * The amount on each row is the most authoritative one that order has: what was
 * captured if it is settled, what was authorized if it is not. Saying which is
 * the point — "held" and "charged" are different facts and a receipt that blurs
 * them is a receipt nobody trusts.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Order;
use Keel\App\Models\OrderPriceBreakdown;
use Keel\App\Services\Pricing\Money;

require __DIR__ . '/partials/top.php';

$rows = $rows ?? [];
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$localDate = static function (?string $utc): string {
    if ($utc === null || $utc === '') {
        return '';
    }

    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('America/New_York'))
        ->format('M j, g:ia');
};

$statusTone = static fn (string $status): string => match ($status) {
    Order::STATUS_DELIVERED => 'badge-good',
    Order::STATUS_CANCELLED, Order::STATUS_REJECTED => 'badge-bad',
    Order::STATUS_NEEDS_ATTENTION => 'badge-warn',
    default => 'badge-brand',
};
?>

<h1 class="h4">Your orders</h1>

<?php if ($rows === []): ?>
<section class="card">
    <div class="empty">
        <span class="empty-art"><?= Deck::icon('receipt', 'icon icon-lg') ?></span>
        <h2 class="empty-title">No orders yet</h2>
        <p class="text-muted">When you order, it will be here — with the receipt and a reorder button.</p>
        <a href="/app" class="btn btn-primary btn-lg">Find something to eat</a>
    </div>
</section>

<?php else: ?>
<ul class="stack stack-2">
    <?php foreach ($rows as $row): ?>
    <?php
    $order = $row['order'];
    $breakdown = $row['breakdown'];
    $settled = $breakdown !== null
        && (string) $breakdown['stage'] === OrderPriceBreakdown::STAGE_FINAL;
    ?>
    <li class="card">
        <a href="/app/orders/<?= (int) $order['id'] ?>" class="card-body stack stack-2 no-underline text-inherit">
            <div class="bar gap-2 wrap">
                <span class="fw-semi">
                    <?= $escape((string) ($row['restaurant']['name'] ?? 'Restaurant')) ?>
                </span>
                <span class="badge <?= $statusTone((string) $order['status']) ?> push">
                    <?= $escape(str_replace('_', ' ', (string) $order['status'])) ?>
                </span>
            </div>

            <p class="text-sm text-muted">
                #<?= (int) $order['id'] ?>
                · <?= $escape($localDate($order['placed_at'] ?? $order['created_at'] ?? null)) ?>
                · <?= (int) $row['item_count'] ?> item<?= (int) $row['item_count'] === 1 ? '' : 's' ?>
            </p>

            <?php if ($breakdown !== null): ?>
            <p class="nums fw-semi">
                <?= $escape(Money::usd((int) $breakdown['total_cents'])) ?>
                <span class="text-sm text-muted fw-normal"><?= $settled ? 'charged' : 'held' ?></span>
            </p>
            <?php endif; ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
