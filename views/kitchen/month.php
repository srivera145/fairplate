<?php
/**
 * What this month will cost.
 *
 * Two of these numbers are real today — the completed-order count and the tier
 * it lands in — and the fee is arithmetic on them. What is not here is a
 * statement, because billing is phase 7, so the panel says the fee is a
 * projection rather than showing a figure that looks like an invoice.
 */

use EchoDial\Deck\Deck;
use Keel\App\Services\Pricing\Money;

require __DIR__ . '/partials/top.php';

$completed = (int) ($completed ?? 0);
$tier = $tier ?? null;
$nextTier = $nextTier ?? null;
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$monthName = \DateTimeImmutable::createFromFormat('Y-m-d', $period . '-01')->format('F Y');

// How far through the current tier this month is, for the bar.
$floor = $tier === null ? 0 : (int) $tier['min_orders'];
$ceiling = $nextTier === null ? null : (int) $nextTier['min_orders'];
$progress = $ceiling === null || $ceiling <= $floor
    ? 100
    : min(100, (int) round((($completed - $floor) / ($ceiling - $floor)) * 100));
?>

<section class="card">
    <div class="card-header">
        <h2 class="card-title"><?= $escape($monthName) ?></h2>
        <span class="badge push">Projection</span>
    </div>
    <div class="card-body stack stack-5">
        <div class="grid grid-2">
            <div class="stat">
                <span class="stat-label">Completed orders</span>
                <span class="stat-value nums"><?= $completed ?></span>
            </div>
            <div class="stat">
                <span class="stat-label">Projected fee</span>
                <span class="stat-value nums">
                    <?php if ($projectedFeeCents === null): ?>
                    &mdash;
                    <?php else: ?>
                    $<?= Money::toDollars((int) $projectedFeeCents) ?>
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <?php if ($tier !== null): ?>
        <div class="stack stack-2">
            <div class="bar">
                <span class="fw-semi">
                    Tier: <?= (int) $tier['min_orders'] ?><?= $tier['max_orders'] === null ? '+' : '–' . (int) $tier['max_orders'] ?> orders
                </span>
                <span class="text-sm text-muted push">
                    <?php if ($nextTier === null): ?>
                    Top tier
                    <?php else: ?>
                    <?= max(0, (int) $nextTier['min_orders'] - $completed) ?> more to the next tier
                    <?php endif; ?>
                </span>
            </div>
            <div class="tier-bar" role="img"
                 aria-label="<?= $progress ?>% of the way to the next tier">
                <span style="inline-size: <?= $progress ?>%"></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($projectedFeeCents === null && $tier !== null && (int) $tier['is_custom'] === 1): ?>
        <div class="alert alert-info">
            <p class="alert-body">
                You are past 500 orders a month, which is a custom rate. FairPlate will agree it with
                you before anything is invoiced.
            </p>
        </div>
        <?php endif; ?>

        <div class="alert alert-good">
            <p class="alert-title">Nothing is deducted from these orders</p>
            <p class="alert-body">
                No commission, no percentage, no processing taken out of your food or tax. You keep the
                menu price and the sales tax in full, and pay one flat monthly fee. Months under 41
                completed orders are free.
            </p>
        </div>

        <p class="text-sm text-muted">
            <?= Deck::icon('info', 'icon icon-sm') ?>
            Fees are billed on America/New_York calendar months. Statements arrive here once billing is
            switched on.
        </p>
    </div>
</section>

<section class="card">
    <div class="card-header"><h2 class="card-title">The whole scale</h2></div>
    <div class="table-wrap">
        <table class="table table-stack">
            <thead>
                <tr><th scope="col">Completed orders</th><th scope="col">Monthly fee</th></tr>
            </thead>
            <tbody>
                <?php foreach ($tiers as $row): ?>
                <tr <?= $tier !== null && (int) $row['id'] === (int) $tier['id'] ? 'class="bg-soft"' : '' ?>>
                    <td><?= (int) $row['min_orders'] ?><?= $row['max_orders'] === null ? '+' : '–' . (int) $row['max_orders'] ?></td>
                    <td class="nums">
                        <?= (int) $row['is_custom'] === 1 ? 'Custom' : '$' . Money::toDollars((int) $row['fee_cents']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/partials/bottom.php'; ?>
