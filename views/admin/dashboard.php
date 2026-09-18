<?php
use EchoDial\Deck\Deck;
use Keel\App\Models\Driver;
use Keel\Core\Csrf;
use Keel\Core\Theme;

$areaTitle = 'FairPlate Admin';
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
