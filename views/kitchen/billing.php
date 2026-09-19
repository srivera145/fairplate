<?php
/**
 * Billing: this month, what it is charged to, and the months before it.
 *
 * The panel at the top is the live one. What is underneath it is the machinery:
 * the payment method the monthly invoice is charged against, the last few
 * statements, and the whole fee scale — which is on this page rather than in a
 * help article because a restaurant looking at a fee is entitled to see the
 * ladder it sits on without leaving the screen.
 */

use EchoDial\Deck\Deck;
use Keel\App\Jobs\MonthlyRestaurantBillingJob;
use Keel\App\Services\Billing\TierBillingService;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;

require __DIR__ . '/partials/top.php';

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

require __DIR__ . '/partials/monthly-panel.php';
?>

<section class="card">
    <div class="card-header">
        <h2 class="card-title">How your fee is paid</h2>
    </div>
    <div class="card-body stack stack-4">
        <?php if (!$stripeConfigured): ?>
        <div class="alert alert-warn">
            <?= Deck::icon('alert-triangle') ?>
            <p class="alert-body">Billing is not configured on this environment yet.</p>
        </div>

        <?php elseif ($paymentMethod === null): ?>
        <p>
            Your monthly fee is charged automatically on the 1st. Add a card or a bank account and
            you will not have to think about it again.
        </p>
        <p class="text-sm text-muted">
            A bank account (ACH) costs FairPlate a few cents to run where a card costs a few dollars,
            and none of that is ever passed on to you &mdash; but it is the reason we ask.
        </p>
        <form method="POST" action="/kitchen/billing/method/start">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-primary">
                <?= Deck::icon('credit-card') ?> Add a payment method
            </button>
        </form>

        <?php else: ?>
        <div class="bar wrap gap-3">
            <span>
                <?= Deck::icon('credit-card') ?>
                <strong><?= $escape($paymentMethod['brand']) ?></strong>
                <?php if ($paymentMethod['last4'] !== ''): ?>
                ending <?= $escape($paymentMethod['last4']) ?>
                <?php endif; ?>
                <span class="text-sm text-muted">&middot; <?= $escape($paymentMethod['kind']) ?></span>
            </span>
            <form method="POST" action="/kitchen/billing/method/start" class="push">
                <?= Csrf::field() ?>
                <button type="submit" class="btn">Replace</button>
            </form>
        </div>
        <p class="text-sm text-muted">
            Charged on the 1st of each month for the month before, net
            <?= (int) MonthlyRestaurantBillingJob::NET_DAYS ?>. Nothing is ever taken out of an order.
        </p>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <h2 class="card-title">Recent statements</h2>
        <?php if ($statementCount > 0): ?>
        <a href="/kitchen/statements" class="btn btn-ghost push">All <?= (int) $statementCount ?></a>
        <?php endif; ?>
    </div>

    <?php if ($recentStatements === []): ?>
    <div class="card-body">
        <p class="text-muted">
            Nothing yet. Your first statement arrives on the 1st of next month.
        </p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table-stack">
            <thead>
                <tr>
                    <th scope="col">Month</th>
                    <th scope="col">Orders</th>
                    <th scope="col">Fee</th>
                    <th scope="col">Saved</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentStatements as $statement): ?>
                <tr>
                    <td><?= $escape(TierBillingService::periodName((string) $statement['period'])) ?></td>
                    <td class="nums"><?= (int) $statement['orders'] ?></td>
                    <td class="nums"><?= $escape(Money::usd((int) $statement['fee_cents'])) ?></td>
                    <td class="nums"><?= $escape(Money::usd((int) $statement['savings_cents'])) ?></td>
                    <td><?php require __DIR__ . '/partials/statement-status.php'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-header"><h2 class="card-title">The whole scale</h2></div>
    <div class="table-wrap">
        <table class="table table-stack">
            <thead>
                <tr><th scope="col">Delivered orders</th><th scope="col">Monthly fee</th></tr>
            </thead>
            <tbody>
                <?php foreach ($tiers as $row): ?>
                <tr <?= $panel['tier_id'] !== null && (int) $row['id'] === (int) $panel['tier_id'] ? 'class="bg-soft"' : '' ?>>
                    <td><?= $escape(TierBillingService::tierName($row)) ?></td>
                    <td class="nums">
                        <?= (int) $row['is_custom'] === 1 ? 'Custom' : $escape(Money::usd((int) $row['fee_cents'])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/partials/bottom.php'; ?>
