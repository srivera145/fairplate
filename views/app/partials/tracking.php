<?php
/**
 * The stepper, the prep time and the driver.
 *
 * Rendered on the page and again on every poll, so there is one description of
 * what a step looks like and it lives here rather than in a template string
 * inside a script.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Order;

$steps = $steps ?? [];
$driver = $driver ?? null;
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$localTime = static function (?string $utc): string {
    if ($utc === null || $utc === '') {
        return '';
    }

    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('America/New_York'))
        ->format('g:ia');
};

$status = (string) $order['status'];
$terminal = in_array($status, Order::TERMINAL_STATUSES, true);
?>
<div class="stack stack-4" data-tracking-body>
    <?php if ($status === Order::STATUS_REJECTED): ?>
    <div class="alert alert-bad">
        <?= Deck::icon('x-circle') ?>
        <div class="alert-body">
            <p class="alert-title">The kitchen could not take this order</p>
            <p><?= $escape((string) ($order['reject_reason'] ?? 'No reason given.')) ?> Your card was released.</p>
        </div>
    </div>
    <?php elseif ($status === Order::STATUS_CANCELLED): ?>
    <div class="alert alert-bad">
        <?= Deck::icon('x-circle') ?>
        <div class="alert-body">
            <p class="alert-title">Cancelled</p>
            <p><?= $escape((string) ($order['cancel_reason'] ?? 'This order was cancelled.')) ?></p>
        </div>
    </div>
    <?php elseif ($status === Order::STATUS_NEEDS_ATTENTION): ?>
    <div class="alert alert-warn">
        <?= Deck::icon('alert-triangle') ?>
        <div class="alert-body">
            <p class="alert-title">We are looking into this order</p>
            <p>Something needs a person. Nothing more is charged while we sort it out.</p>
        </div>
    </div>
    <?php endif; ?>

    <ol class="stepper stepper-vertical">
        <?php foreach ($steps as $step): ?>
        <li class="step <?= $step['done'] ? 'is-done' : '' ?> <?= $step['current'] ? 'is-current' : '' ?>">
            <span class="step-marker"></span>
            <span class="step-label"><?= $escape((string) $step['label']) ?></span>
            <span class="step-note tracking-step-time">
                <?php if ($step['at'] !== null): ?>
                <?= $escape($localTime((string) $step['at'])) ?>
                <?php elseif ($step['current'] && !$terminal): ?>
                In progress
                <?php endif; ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ol>

    <?php if (($order['prep_minutes'] ?? null) !== null): ?>
    <p class="text-sm">
        <?= Deck::icon('clock', 'icon icon-sm') ?>
        The kitchen said <strong><?= (int) $order['prep_minutes'] ?> minutes</strong> to cook this.
    </p>
    <?php endif; ?>

    <?php if ($driver !== null): ?>
    <div class="card">
        <div class="card-body stack stack-1">
            <p class="fw-semi">
                <?= Deck::icon('truck', 'icon icon-sm') ?>
                <?= $escape((string) $driver['first_name']) ?> is your driver
            </p>
            <?php if ((string) $driver['vehicle'] !== ''): ?>
            <p class="text-sm text-muted"><?= $escape((string) $driver['vehicle']) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif (!$terminal): ?>
    <p class="text-sm text-muted">A driver is assigned once the food is nearly ready.</p>
    <?php endif; ?>
</div>
