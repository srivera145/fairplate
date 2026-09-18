<?php
/**
 * What the day and the week paid, and every run behind those numbers.
 *
 * The two totals are at the top because they are what the screen is opened for.
 * The list below is why it can be trusted: base, miles, wait and tip for each
 * delivery, adding up to the figure above. A driver comparing FairPlate against
 * the app in the other phone holder is comparing numbers they can check.
 *
 * Where base plus miles comes to less than the guarantee, the difference is
 * called out as the minimum payout. It is the line drivers ask about — a
 * half-mile run that pays five dollars looks like an arithmetic error until
 * somebody explains the floor — and this screen should be the thing that
 * explains it rather than a support conversation.
 */

use EchoDial\Deck\Deck;
use Keel\App\Services\Pricing\Money;

$today = $today ?? [];
$week = $week ?? [];
$deliveries = $deliveries ?? [];
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$localTime = static function (?string $utc, string $format): string {
    if ($utc === null || $utc === '') {
        return '';
    }

    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('America/New_York'))
        ->format($format);
};

require __DIR__ . '/partials/top.php';
?>

<section class="grid grid-fixed-2 gap-3">
    <div class="card">
        <div class="card-body stack stack-0">
            <p class="stat-label">Today</p>
            <p class="earnings-total"><?= $escape(Money::usd((int) ($today['total_cents'] ?? 0))) ?></p>
            <p class="text-sm text-muted">
                <?= (int) ($today['count'] ?? 0) ?> <?= (int) ($today['count'] ?? 0) === 1 ? 'delivery' : 'deliveries' ?>
            </p>
        </div>
    </div>
    <div class="card">
        <div class="card-body stack stack-0">
            <p class="stat-label">This week</p>
            <p class="earnings-total"><?= $escape(Money::usd((int) ($week['total_cents'] ?? 0))) ?></p>
            <p class="text-sm text-muted">
                since <?= $escape(($weekStartsOn ?? null) instanceof DateTimeImmutable ? $weekStartsOn->format('D j M') : 'Monday') ?>
            </p>
        </div>
    </div>
</section>

<section class="card">
    <div class="card-body stack stack-3">
        <h2 class="h6">This week, by line</h2>
        <dl class="earnings-lines">
            <div class="earnings-line">
                <dt>Guaranteed pay</dt>
                <dd><?= $escape(Money::usd((int) ($week['guaranteed_cents'] ?? 0))) ?></dd>
            </div>
            <div class="earnings-line">
                <dt>Wait pay</dt>
                <dd><?= $escape(Money::usd((int) ($week['wait_cents'] ?? 0))) ?></dd>
            </div>
            <div class="earnings-line">
                <dt>Tips</dt>
                <dd><?= $escape(Money::usd((int) ($week['tip_cents'] ?? 0))) ?></dd>
            </div>
            <div class="earnings-line earnings-line-total">
                <dt>Total</dt>
                <dd><?= $escape(Money::usd((int) ($week['total_cents'] ?? 0))) ?></dd>
            </div>
        </dl>
        <p class="text-sm text-muted">
            Guaranteed pay is the base plus the mileage, or the minimum payout where that is
            higher. Tips are yours in full — FairPlate takes nothing off any of this.
        </p>
    </div>
</section>

<section class="card">
    <div class="card-body stack stack-3">
        <h2 class="h6">Deliveries</h2>

        <?php if ($deliveries === []): ?>
        <div class="empty">
            <span class="empty-art"><?= Deck::icon('receipt', 'icon icon-lg') ?></span>
            <h3 class="empty-title">Nothing yet this week</h3>
            <p class="text-muted">Finished deliveries show up here with every line broken out.</p>
        </div>
        <?php else: ?>
        <ul class="earnings-runs stack stack-3" role="list">
            <?php foreach ($deliveries as $run): ?>
            <li class="earnings-run">
                <div class="bar gap-2">
                    <div class="stack stack-0 min-w-0">
                        <span class="fw-semi truncate"><?= $escape((string) $run['restaurant_name']) ?></span>
                        <span class="text-sm text-muted">
                            <?= $escape($localTime((string) $run['delivered_at'], 'D j M, g:ia')) ?>
                            · <?= $escape(rtrim(rtrim(number_format((float) $run['route_miles'], 1, '.', ''), '0'), '.')) ?> mi
                        </span>
                    </div>
                    <span class="earnings-run-total push"><?= $escape(Money::usd((int) $run['total_cents'])) ?></span>
                </div>
                <dl class="earnings-run-lines">
                    <div class="earnings-line">
                        <dt>Base</dt>
                        <dd><?= $escape(Money::usd((int) $run['driver_base_cents'])) ?></dd>
                    </div>
                    <div class="earnings-line">
                        <dt>Miles</dt>
                        <dd><?= $escape(Money::usd((int) $run['driver_mileage_cents'])) ?></dd>
                    </div>
                    <?php if ((int) $run['minimum_topped_up_cents'] > 0): ?>
                    <div class="earnings-line">
                        <dt>Minimum payout</dt>
                        <dd>+<?= $escape(Money::usd((int) $run['minimum_topped_up_cents'])) ?></dd>
                    </div>
                    <?php endif; ?>
                    <div class="earnings-line">
                        <dt>Wait</dt>
                        <dd><?= $escape(Money::usd((int) $run['wait_pay_cents'])) ?></dd>
                    </div>
                    <div class="earnings-line">
                        <dt>Tip</dt>
                        <dd><?= $escape(Money::usd((int) $run['tip_cents'])) ?></dd>
                    </div>
                </dl>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/partials/bottom.php'; ?>
