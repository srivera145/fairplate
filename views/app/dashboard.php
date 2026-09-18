<?php
use EchoDial\Deck\Deck;
use Keel\Core\Theme;

$areaTitle = 'FairPlate';
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

        <section class="card">
            <div class="card-body stack stack-4">
                <div class="bar">
                    <h2 class="h5">Your account</h2>
                    <span class="badge <?= !empty($isMember) ? 'badge-brand' : '' ?> push"><?= !empty($isMember) ? 'Member' : 'No membership' ?></span>
                </div>
                <div class="stat">
                    <dt class="stat-label">Saved addresses</dt>
                    <dd class="fw-semi"><?= count($addresses ?? []) ?></dd>
                </div>
                <div class="stat">
                    <dt class="stat-label">Orders placed</dt>
                    <dd class="fw-semi"><?= count($orders ?? []) ?></dd>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-body stack stack-4">
                <h2 class="h5">Open near you</h2>
                <?php if (empty($restaurants)): ?>
                <p class="text-muted">No restaurants are taking orders yet.</p>
                <?php else: ?>
                <ul class="stack stack-2">
                    <?php foreach ($restaurants as $restaurant): ?>
                    <li>
                        <span class="fw-semi"><?= htmlspecialchars((string) $restaurant['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="text-sm text-muted"><?= htmlspecialchars((string) $restaurant['city'], ENT_QUOTES, 'UTF-8') ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <p class="text-sm text-muted">Ordering arrives in a later phase.</p>
            </div>
        </section>
    </main>
</body>
</html>
