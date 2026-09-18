<?php
use EchoDial\Deck\Deck;
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
