<?php
/**
 * Every month FairPlate has billed this restaurant.
 *
 * One row per month, with the whole arithmetic on it: how many orders, what
 * they sold for, what a commission marketplace would have taken, what FairPlate
 * charged instead, and the difference. A restaurant should be able to check
 * this by hand with a calculator, so every number the fee was worked out from
 * is on the row rather than only the answer.
 *
 * The PDF and the payment page are Stripe's own links, saved when the invoice
 * was finalized rather than fetched now. A year of history would otherwise be a
 * year of API calls on every page load, and a page that cannot render when
 * Stripe is slow.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\RestaurantMonthlyStatement;
use Keel\App\Services\Billing\TierBillingService;
use Keel\App\Services\Pricing\Money;

require __DIR__ . '/partials/top.php';

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>

<section class="card">
    <div class="card-header">
        <h2 class="card-title">Statements</h2>
        <a href="/kitchen/billing" class="btn btn-ghost push">This month</a>
    </div>

    <?php if ($statements === []): ?>
    <div class="card-body">
        <p class="text-muted">
            No statements yet. The first one is written on the 1st of the month after your first
            delivered order.
        </p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table table-stack">
            <thead>
                <tr>
                    <th scope="col">Month</th>
                    <th scope="col">Orders</th>
                    <th scope="col">Food sold</th>
                    <th scope="col">A <?= $escape($comparisonPct) ?>% app would take</th>
                    <th scope="col">Your fee</th>
                    <th scope="col">You saved</th>
                    <th scope="col">Status</th>
                    <th scope="col">Invoice</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statements as $statement): ?>
                <?php
                $isFree = (string) $statement['status'] === RestaurantMonthlyStatement::STATUS_FREE;
                $needsReview = (string) $statement['status'] === RestaurantMonthlyStatement::STATUS_NEEDS_REVIEW;
                $pdf = trim((string) ($statement['invoice_pdf_url'] ?? ''));
                $hosted = trim((string) ($statement['hosted_invoice_url'] ?? ''));
                ?>
                <tr>
                    <th scope="row"><?= $escape(TierBillingService::periodName((string) $statement['period'])) ?></th>
                    <td class="nums"><?= (int) $statement['orders'] ?></td>
                    <td class="nums"><?= $escape(Money::usd((int) $statement['sales_subtotal_cents'])) ?></td>
                    <td class="nums"><?= $escape(Money::usd((int) $statement['commission_equiv_cents'])) ?></td>
                    <td class="nums">
                        <?php if ($needsReview): ?>
                        &mdash;
                        <?php else: ?>
                        <?= $escape(Money::usd((int) $statement['fee_cents'])) ?>
                        <?php if ((int) $statement['discount_cents'] > 0): ?>
                        <span class="text-sm text-muted">
                            after <?= $escape(Money::usd((int) $statement['discount_cents'])) ?> off
                        </span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="nums"><?= $needsReview ? '&mdash;' : $escape(Money::usd((int) $statement['savings_cents'])) ?></td>
                    <td>
                        <div class="stack stack-0">
                            <?php require __DIR__ . '/partials/statement-status.php'; ?>
                            <?php if (trim((string) ($statement['due_on'] ?? '')) !== ''): ?>
                            <span class="text-sm text-muted">
                                Due <?= $escape((new DateTimeImmutable((string) $statement['due_on']))->format('M j, Y')) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($pdf !== ''): ?>
                        <a href="<?= $escape($pdf) ?>" class="btn btn-ghost" rel="noopener noreferrer" target="_blank">
                            <?= Deck::icon('download') ?> PDF
                        </a>
                        <?php elseif ($hosted !== ''): ?>
                        <a href="<?= $escape($hosted) ?>" class="btn btn-ghost" rel="noopener noreferrer" target="_blank">
                            View
                        </a>
                        <?php elseif ($isFree): ?>
                        <span class="text-sm text-muted">No invoice</span>
                        <?php else: ?>
                        <span class="text-sm text-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card-body">
        <p class="text-sm text-muted">
            <?= Deck::icon('info', 'icon icon-sm') ?>
            "Food sold" is the menu price of everything delivered that month, before tax. Nothing was
            deducted from it &mdash; the comparison column is what a marketplace charging
            <?= $escape($comparisonPct) ?>% commission would have taken, and it is shown for contrast only.
        </p>
    </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/bottom.php'; ?>
