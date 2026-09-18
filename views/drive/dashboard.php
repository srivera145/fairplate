<?php
use EchoDial\Deck\Deck;
use Keel\Core\Theme;

$areaTitle = 'FairPlate Drive';
?>
<!DOCTYPE html>
<html <?= Deck::htmlAttributes(lang: 'en') ?> <?= Deck::theme(mode: Theme::serverPreference()) ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body>
    <span id="top" tabindex="-1"></span>

    <main class="container stack stack-6" style="--container: 48rem">
        <?php require __DIR__ . '/../partials/role-header.php'; ?>

        <?php if (empty($driver)): ?>
        <section class="card">
            <div class="empty">
                <span class="empty-art"><?= Deck::icon('car', 'icon icon-lg') ?></span>
                <h2 class="empty-title">No driver profile</h2>
                <p>Your account has no driver record yet. An admin can set one up.</p>
            </div>
        </section>
        <?php else: ?>
        <section class="card">
            <div class="card-body stack stack-4">
                <div class="bar">
                    <h2 class="h5">Status</h2>
                    <span class="badge <?= (int) $driver['approved'] === 1 ? 'badge-brand' : '' ?> push">
                        <?= (int) $driver['approved'] === 1 ? 'Approved' : 'Pending approval' ?>
                    </span>
                </div>
                <div class="stat">
                    <dt class="stat-label">Taking offers</dt>
                    <dd class="fw-semi"><?= (int) $driver['online'] === 1 ? 'Online' : 'Offline' ?></dd>
                </div>
                <div class="stat">
                    <dt class="stat-label">Vehicle</dt>
                    <dd class="fw-semi">
                        <?= htmlspecialchars(trim(($driver['vehicle_color'] ?? '') . ' ' . ($driver['vehicle_make'] ?? '') . ' ' . ($driver['vehicle_model'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                    </dd>
                </div>
                <div class="stat">
                    <dt class="stat-label">Open offers</dt>
                    <dd class="fw-semi"><?= count($offers ?? []) ?></dd>
                </div>
                <div class="stat">
                    <dt class="stat-label">Deliveries on record</dt>
                    <dd class="fw-semi"><?= count($orders ?? []) ?></dd>
                </div>
                <p class="text-sm text-muted">Every offer will show guaranteed pay and both distances before you accept.</p>
            </div>
        </section>
        <?php endif; ?>
    </main>
</body>
</html>
