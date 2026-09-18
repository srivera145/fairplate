<?php
use EchoDial\Deck\Deck;
use Keel\Core\Theme;

$areaTitle = 'FairPlate Kitchen';
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

        <?php if (empty($restaurant)): ?>
        <section class="card">
            <div class="empty">
                <span class="empty-art"><?= Deck::icon('store', 'icon icon-lg') ?></span>
                <h2 class="empty-title">No restaurant yet</h2>
                <p>Your account is not attached to a restaurant. An admin can link it.</p>
            </div>
        </section>
        <?php else: ?>
        <section class="card">
            <div class="card-body stack stack-4">
                <div class="bar">
                    <h2 class="h5"><?= htmlspecialchars((string) $restaurant['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <span class="badge push uppercase"><?= htmlspecialchars((string) $restaurant['status'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="stat">
                    <dt class="stat-label">Taking orders</dt>
                    <dd class="fw-semi"><?= (int) $restaurant['paused'] === 1 ? 'Paused' : 'Yes' ?></dd>
                </div>
                <div class="stat">
                    <dt class="stat-label">Orders on record</dt>
                    <dd class="fw-semi"><?= count($orders ?? []) ?></dd>
                </div>
                <p class="text-sm text-muted">You pay one flat monthly fee. Nothing is deducted from these orders.</p>
            </div>
        </section>
        <?php endif; ?>

        <?php if (count($restaurants ?? []) > 1): ?>
        <section class="card">
            <div class="card-body stack stack-2">
                <h2 class="h5">Your other restaurants</h2>
                <ul class="stack stack-2">
                    <?php foreach (array_slice($restaurants, 1) as $other): ?>
                    <li class="fw-semi"><?= htmlspecialchars((string) $other['name'], ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
        <?php endif; ?>
    </main>
</body>
</html>
