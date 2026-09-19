<?php
/**
 * This month, live.
 *
 * Every figure here comes from one TierBillingService::statementFor() call —
 * the same call the billing job makes on the first of the month — so the
 * projected fee a kitchen watches all month and the fee it is invoiced are the
 * same arithmetic rather than two that have to agree.
 *
 * Expects $panel (that array), and optionally $period when the caller wants to
 * label something other than the month in progress.
 *
 * The savings line is the argument FairPlate is making, stated in dollars: what
 * a quarter of this month's food would have been, what the flat fee is instead,
 * and the difference. It is shown even when the difference is negative, because
 * a panel that hid a bad month would be a sales page.
 */

use EchoDial\Deck\Deck;
use Keel\App\Services\Billing\TierBillingService;
use Keel\App\Services\Pricing\Money;

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$monthName = TierBillingService::periodName((string) $panel['period']);
$feeCents = $panel['fee_cents'];
$isCustomUnset = $feeCents === null;
$toNext = $panel['orders_to_next_tier'];
$nextTierName = $panel['next_tier'] === null ? null : TierBillingService::tierName($panel['next_tier']);
?>

<section class="card">
    <div class="card-header">
        <h2 class="card-title"><?= $escape($monthName) ?></h2>
        <span class="badge push">So far this month</span>
    </div>
    <div class="card-body stack stack-5">
        <div class="grid grid-3">
            <div class="stat">
                <span class="stat-label">Orders this month</span>
                <span class="stat-value nums"><?= (int) $panel['orders'] ?></span>
            </div>
            <div class="stat">
                <span class="stat-label">Current tier</span>
                <span class="stat-value"><?= $escape((string) $panel['tier_name']) ?></span>
            </div>
            <div class="stat">
                <span class="stat-label">Projected fee</span>
                <span class="stat-value nums">
                    <?= $isCustomUnset ? '&mdash;' : $escape(Money::usd((int) $feeCents)) ?>
                </span>
            </div>
        </div>

        <?php if ((int) $panel['discount_cents'] > 0): ?>
        <p class="text-sm text-muted">
            Founding partner: <?= $escape(Money::usd((int) $panel['list_fee_cents'])) ?> less
            <?= $escape(Money::usd((int) $panel['discount_cents'])) ?> off.
        </p>
        <?php endif; ?>

        <div class="stack stack-2">
            <div class="bar">
                <span class="fw-semi">Progress through this tier</span>
                <span class="text-sm text-muted push">
                    <?php if ($toNext === null || $nextTierName === null): ?>
                    Top tier
                    <?php else: ?>
                    <?= (int) $toNext ?> more <?= $toNext === 1 ? 'order' : 'orders' ?> to <?= $escape($nextTierName) ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="tier-bar" role="img"
                 aria-label="<?= (int) $panel['progress_pct'] ?>% of the way to the next tier">
                <span style="inline-size: <?= (int) $panel['progress_pct'] ?>%"></span>
            </div>
        </div>

        <div class="alert alert-good">
            <p class="alert-title">
                Big-app cost this month: <?= $escape(Money::usd((int) $panel['commission_equiv_cents'])) ?>
                &middot; Your fee: <?= $isCustomUnset ? 'to be agreed' : $escape(Money::usd((int) $feeCents)) ?>
                <?php if (!$isCustomUnset): ?>
                &middot; You're saving <?= $escape(Money::usd((int) $panel['savings_cents'])) ?>
                <?php endif; ?>
            </p>
            <p class="alert-body">
                Nothing is deducted from these orders. No commission, no percentage, no processing taken
                out of your food or tax &mdash; you keep the menu price and the sales tax in full, and pay
                one flat monthly fee.
                <?php if ($panel['free_threshold'] !== null): ?>
                Months under <?= (int) $panel['free_threshold'] ?> delivered orders are free.
                <?php endif; ?>
            </p>
        </div>

        <?php if ($isCustomUnset): ?>
        <div class="alert alert-info">
            <p class="alert-body">
                <?= $escape((string) $panel['tier_name']) ?> is a custom rate. FairPlate will agree it with
                you before anything is invoiced.
            </p>
        </div>
        <?php endif; ?>

        <p class="text-sm text-muted">
            <?= Deck::icon('info', 'icon icon-sm') ?>
            Based on <?= $escape(Money::usd((int) $panel['sales_subtotal_cents'])) ?> of food delivered so far.
            Fees are billed on America/New_York calendar months and invoiced on the 1st.
        </p>
    </div>
</section>
