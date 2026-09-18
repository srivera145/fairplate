<?php
/**
 * The charge lines, the total, and the promise about the worst case.
 *
 * Rendered on first paint and again after every tip change — the checkout
 * endpoint hands this same markup back as a string — so the page never has to
 * add anything up and there is no arithmetic in a script that could disagree
 * with what the card is held for.
 *
 * The lines and their labels come from Breakdown, which is where the spec's
 * seven charge lines and their exact wording live. "Service fee" is never
 * "surcharge" and never "card fee", and this view has no opinion about that.
 *
 * The notes are <dd> elements rather than paragraphs: a definition list may
 * carry several descriptions for one term, which is exactly what a line and the
 * sentence explaining it are.
 */

use Keel\App\Services\Pricing\Breakdown;
use Keel\App\Services\Pricing\Money;

$estimate = $estimate ?? null;
$errors = $errors ?? [];
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<div class="stack stack-4" data-breakdown>
    <?php if ($errors !== []): ?>
    <div class="alert alert-bad" role="alert">
        <div class="alert-body stack stack-1">
            <?php foreach ($errors as $error): ?>
            <p><?= $escape((string) $error) ?></p>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($estimate !== null): ?>
    <dl class="breakdown">
        <?php foreach ($estimate->displayLines() as $row): ?>
        <dt><?= $escape((string) $row['label']) ?></dt>
        <dd class="nums"><?= $escape(Money::usd((int) $row['cents'])) ?></dd>

        <?php if ($row['key'] === Breakdown::DRIVER_PAY && (float) ($routeMiles ?? 0) > 0): ?>
        <dd class="breakdown-note">
            <?= $escape(number_format((float) $routeMiles, 2)) ?> miles from the restaurant to you.
            Tips go 100% to your driver.
        </dd>
        <?php endif; ?>

        <?php if ($row['key'] === Breakdown::WAIT_PAY): ?>
        <?php // One line, unbroken: this sentence is the spec's wording verbatim. ?>
        <dd class="breakdown-note">Wait pay: $0 unless the restaurant is delayed (max <?= $escape(Money::usd((int) $waitCapCents)) ?>).</dd>
        <?php endif; ?>

        <?php if ($row['key'] === Breakdown::PLATFORM_FEE && (int) $row['cents'] === 0 && !empty($isMember)): ?>
        <dd class="breakdown-note">Members pay no platform fee.</dd>
        <?php endif; ?>
        <?php endforeach; ?>

        <dt class="breakdown-total">Charged now</dt>
        <dd class="breakdown-total nums"><?= $escape(Money::usd((int) $estimateTotal)) ?></dd>
    </dl>

    <p class="text-sm">
        You'll be charged <strong><?= $escape(Money::usd((int) $estimateTotal)) ?></strong>.
        Max <strong><?= $escape(Money::usd((int) $authorizedTotal)) ?></strong> if the restaurant is delayed.
    </p>

    <?php if (!empty($memberNudgeCents)): ?>
    <div class="alert alert-info">
        <p class="alert-body">
            Members pay $0 platform fees — you'd have saved
            <strong><?= $escape(Money::usd((int) $memberNudgeCents)) ?></strong> this month.
            <a href="/app/membership" class="link-quiet">See membership</a>
        </p>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
