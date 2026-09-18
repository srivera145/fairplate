<?php
use EchoDial\Deck\Deck;
use Keel\App\Models\Driver;
use Keel\App\Models\Payout;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;
use Keel\Core\Theme;

$areaTitle = 'FairPlate Admin';
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html <?= Deck::htmlAttributes(lang: 'en') ?> <?= Deck::theme(mode: Theme::serverPreference()) ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body>
    <span id="top" tabindex="-1"></span>

    <main class="container stack stack-6" style="--container: 56rem">
        <?php require __DIR__ . '/../partials/role-header.php'; ?>

        <?php if (($flash ?? null) !== null): ?>
        <div class="alert alert-<?= $escape((string) $flash['tone']) ?>" role="status">
            <p class="alert-body"><?= $escape((string) $flash['message']) ?></p>
        </div>
        <?php endif; ?>

        <section class="card">
            <div class="card-body stack stack-4">
                <h2 class="h5">Platform</h2>
                <div class="stat">
                    <dt class="stat-label">Restaurants</dt>
                    <dd class="fw-semi"><?= (int) ($restaurantCount ?? 0) ?></dd>
                </div>
                <div class="stat">
                    <dt class="stat-label">Drivers</dt>
                    <dd class="fw-semi"><?= (int) ($driverCount ?? 0) ?></dd>
                </div>
                <div class="stat">
                    <dt class="stat-label">Orders</dt>
                    <dd class="fw-semi"><?= (int) ($orderCount ?? 0) ?></dd>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-body stack stack-4">
                <h2 class="h5">Drivers awaiting approval</h2>
                <?php if (empty($pendingDrivers)): ?>
                <p class="text-muted">Nobody is waiting. Every driver who has applied is cleared to take offers.</p>
                <?php else: ?>
                <p class="text-sm text-muted">
                    A driver cannot be offered anything until you approve them. Check the vehicle and the
                    phone number against whatever you asked for off-platform, then clear them.
                </p>
                <ul class="stack stack-3" role="list">
                    <?php foreach ($pendingDrivers as $pendingDriver): ?>
                    <li class="bar gap-3">
                        <div class="stack stack-0 min-w-0">
                            <span class="fw-semi"><?= htmlspecialchars((string) ($pendingDriver['user_name'] ?? 'Driver'), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="text-sm text-muted">
                                <?= htmlspecialchars((string) ($pendingDriver['user_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                ·
                                <?= htmlspecialchars(Driver::vehicleLine($pendingDriver), ENT_QUOTES, 'UTF-8') ?>
                                <?php if (trim((string) ($pendingDriver['plate'] ?? '')) !== ''): ?>
                                · <?= htmlspecialchars((string) $pendingDriver['plate'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </span>
                            <span class="text-sm <?= trim((string) ($pendingDriver['stripe_account_id'] ?? '')) === '' ? 'text-warn' : 'text-muted' ?>">
                                <?= trim((string) ($pendingDriver['stripe_account_id'] ?? '')) === ''
                                    ? 'Payouts not connected yet'
                                    : 'Payouts connected' ?>
                            </span>
                        </div>
                        <form method="POST" action="/admin/drivers/<?= (int) $pendingDriver['id'] ?>/approve" class="push">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn-primary">Approve</button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-body stack stack-4">
                <h2 class="h5">Payouts needing a person</h2>
                <?php if (empty($stuckPayouts)): ?>
                <p class="text-muted">Nothing is stuck. Every transfer has gone out.</p>
                <?php else: ?>
                <p class="text-sm text-muted">
                    Held means the recipient cannot be paid yet — usually an account that has not
                    finished onboarding. Failed means five attempts went nowhere. Neither is lost: the
                    amount is still owed and sending it again is one button.
                </p>
                <ul class="stack stack-3" role="list">
                    <?php foreach ($stuckPayouts as $payout): ?>
                    <li class="bar gap-3">
                        <div class="stack stack-0 min-w-0">
                            <span class="fw-semi">
                                <?= $escape(Money::usd((int) $payout['amount_cents'])) ?>
                                to the <?= $escape(str_replace('_', ' ', (string) $payout['type'])) ?>
                                · order #<?= (int) $payout['order_id'] ?>
                            </span>
                            <span class="text-sm text-muted">
                                <?= $escape((string) $payout['restaurant_name']) ?>
                                ·
                                <span class="<?= (string) $payout['status'] === Payout::STATUS_FAILED ? 'text-bad' : 'text-warn' ?>">
                                    <?= $escape((string) $payout['status']) ?>
                                </span>
                                after <?= (int) $payout['attempts'] ?> attempt(s)
                            </span>
                            <?php if (trim((string) ($payout['last_error'] ?? '')) !== ''): ?>
                            <span class="text-sm text-muted"><?= $escape((string) $payout['last_error']) ?></span>
                            <?php endif; ?>
                        </div>
                        <form method="POST" action="/admin/payouts/<?= (int) $payout['id'] ?>/retry" class="push">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn">Send again</button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-body stack stack-4">
                <h2 class="h5">Refunds</h2>
                <?php if (empty($capturedOrders)): ?>
                <p class="text-muted">Nothing has been charged yet.</p>
                <?php else: ?>
                <p class="text-sm text-muted">
                    The platform absorbs a refund. Tick "restaurant's fault" only when the kitchen caused
                    it — that is the one thing here that takes money back off a restaurant, and it takes
                    back their portion only. The driver is paid either way.
                </p>
                <ul class="stack stack-4" role="list">
                    <?php foreach ($capturedOrders as $captured): ?>
                    <?php $left = (int) $captured['captured_cents'] - (int) $captured['refunded_cents']; ?>
                    <li class="stack stack-2">
                        <div class="stack stack-0">
                            <span class="fw-semi">
                                #<?= (int) $captured['id'] ?>
                                · <?= $escape((string) $captured['restaurant_name']) ?>
                                · <?= $escape(Money::usd((int) $captured['captured_cents'])) ?> charged
                            </span>
                            <span class="text-sm text-muted">
                                <?= $escape((string) $captured['customer_name']) ?>
                                <?php if ((int) $captured['refunded_cents'] > 0): ?>
                                · <?= $escape(Money::usd((int) $captured['refunded_cents'])) ?> already refunded
                                <?php endif; ?>
                                · <?= $escape(Money::usd(max(0, $left))) ?> refundable
                            </span>
                        </div>
                        <?php if ($left > 0): ?>
                        <form method="POST" action="/admin/orders/<?= (int) $captured['id'] ?>/refund" class="stack stack-2">
                            <?= Csrf::field() ?>
                            <div class="field-row">
                                <div class="field">
                                    <label class="label" for="amount-<?= (int) $captured['id'] ?>">Amount</label>
                                    <input type="text" class="input" inputmode="decimal" required
                                           id="amount-<?= (int) $captured['id'] ?>" name="amount"
                                           placeholder="<?= $escape(Money::toDollars($left)) ?>">
                                </div>
                                <div class="field">
                                    <label class="label" for="reason-<?= (int) $captured['id'] ?>">Why</label>
                                    <input type="text" class="input" maxlength="255"
                                           id="reason-<?= (int) $captured['id'] ?>" name="reason"
                                           placeholder="Missing an item">
                                </div>
                            </div>
                            <label class="check">
                                <input type="checkbox" name="restaurant_error" value="1">
                                <span class="check-text">The restaurant's fault — reverse their portion</span>
                            </label>
                            <button type="submit" class="btn">Refund</button>
                        </form>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card-body stack stack-4">
                <h2 class="h5">Settings</h2>
                <?php if (empty($settings)): ?>
                <p class="text-muted">No settings are seeded. Run the FairPlate seeder.</p>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr><th scope="col">Key</th><th scope="col">Value</th><th scope="col">Type</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settings as $setting): ?>
                        <tr>
                            <td data-label="Key" class="mono"><?= htmlspecialchars((string) $setting['key'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Value" class="mono"><?= htmlspecialchars((string) $setting['value'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Type" class="uppercase"><?= htmlspecialchars((string) $setting['type'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="text-sm text-muted">Editing lands in a later phase. Changes never restate an existing order.</p>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
