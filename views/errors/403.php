<?php
use EchoDial\Deck\Deck;
use Keel\Core\Theme;
?>
<!DOCTYPE html>
<html <?= Deck::htmlAttributes(lang: 'en') ?> <?= Deck::theme(mode: Theme::serverPreference()) ?>>
<head>
<?php require __DIR__ . '/../partials/head.php'; ?>
</head>
<body>
    <span id="top" tabindex="-1"></span>
    <?php $themeToggleClass = 'theme-toggle-floating'; require __DIR__ . '/../partials/theme-toggle.php'; ?>

    <main class="container stage">
        <section class="card">
            <div class="empty">
                <span class="empty-art"><?= Deck::icon('lock', 'icon icon-lg') ?></span>
                <span class="badge">403</span>
                <h1 class="empty-title">Not your area</h1>
                <p>This part of FairPlate belongs to a different kind of account.</p>
                <a href="<?= htmlspecialchars((string) ($homePath ?? '/login'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">Go to your dashboard</a>
            </div>
        </section>
    </main>
</body>
</html>
