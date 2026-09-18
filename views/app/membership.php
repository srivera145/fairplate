<?php
/**
 * Membership: the price, the benefits, and one button.
 *
 * The savings figure is what this customer's platform fees actually came to
 * this month, including the processing those fees dragged along. It is shown to
 * members and non-members alike and it is never rounded up into a claim — the
 * page says what the number is and lets it argue for itself.
 */

use EchoDial\Deck\Deck;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;

require __DIR__ . '/partials/top.php';

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$priceCents = $priceCents ?? null;
$savedThisMonthCents = $savedThisMonthCents ?? null;

$periodEnd = static function (?string $utc): string {
    if ($utc === null || $utc === '') {
        return '';
    }

    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('America/New_York'))
        ->format('F j, Y');
};
?>

<?php if (!empty($justSubscribed) && !$isMember): ?>
<div class="alert alert-info" role="status">
    <?= Deck::icon('clock') ?>
    <p class="alert-body">Thanks — Stripe is confirming your membership. It shows here the moment it lands.</p>
</div>
<?php endif; ?>

<section class="card <?= $isMember ? 'card-brand' : '' ?>">
    <div class="card-body stack stack-4">
        <div class="bar gap-2 wrap">
            <h1 class="h4">FairPlate Membership</h1>
            <?php if ($isMember): ?>
            <span class="badge badge-good push"><?= $isEnding ? 'Ending' : 'Active' ?></span>
            <?php endif; ?>
        </div>

        <?php if ($priceCents !== null): ?>
        <p class="stat">
            <span class="stat-value"><?= $escape(Money::usd((int) $priceCents)) ?></span>
            <span class="stat-label">per month</span>
        </p>
        <?php endif; ?>

        <ul class="stack stack-2">
            <?php foreach (($benefits ?? []) as $benefit): ?>
            <li><?= Deck::icon('check', 'icon icon-sm text-brand') ?> <?= $escape((string) $benefit) ?></li>
            <?php endforeach; ?>
        </ul>

        <?php if ($isMember && $isEnding && ($membership['current_period_end'] ?? null) !== null): ?>
        <div class="alert alert-warn">
            <p class="alert-body">
                Your membership runs until <?= $escape($periodEnd((string) $membership['current_period_end'])) ?>
                and will not renew. You can restart it from the billing portal.
            </p>
        </div>
        <?php elseif ($isMember && ($membership['current_period_end'] ?? null) !== null): ?>
        <p class="text-sm text-muted">
            Renews <?= $escape($periodEnd((string) $membership['current_period_end'])) ?>.
        </p>
        <?php endif; ?>

        <?php if ($priceCents === null): ?>
        <div class="alert alert-warn">
            <?= Deck::icon('alert-triangle') ?>
            <p class="alert-body">Memberships are not available on this environment yet.</p>
        </div>

        <?php elseif ($isMember): ?>
        <form method="POST" action="/app/membership/portal">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-lg btn-block">
                Manage or cancel in the billing portal
            </button>
        </form>

        <?php else: ?>
        <form method="POST" action="/app/membership/subscribe">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-primary btn-lg btn-block">
                Join for <?= $escape(Money::usd((int) $priceCents)) ?> a month
            </button>
        </form>
        <?php endif; ?>
    </div>
</section>

<?php if ($savedThisMonthCents !== null && $priceCents !== null): ?>
<section class="card">
    <div class="card-body stack stack-2">
        <h2 class="h6">This month</h2>
        <?php if ((int) $savedThisMonthCents <= 0): ?>
        <p class="text-muted">
            You have paid no platform fees this month, so a membership would not have saved you
            anything yet.
        </p>
        <?php elseif ($isMember): ?>
        <p>
            Your membership has saved you
            <strong><?= $escape(Money::usd((int) $savedThisMonthCents)) ?></strong> in platform fees
            this month, against <?= $escape(Money::usd((int) $priceCents)) ?> of membership.
        </p>
        <?php else: ?>
        <p>
            You have paid <strong><?= $escape(Money::usd((int) $savedThisMonthCents)) ?></strong> in
            platform fees this month. A membership costs
            <?= $escape(Money::usd((int) $priceCents)) ?>.
        </p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
