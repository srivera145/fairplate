<?php
/**
 * The header every FairPlate area shares: who you are, the theme switch, and
 * the way out. Set $areaTitle before requiring it; $user is optional.
 *
 * Sign out posts, so it carries the CSRF field like every other form.
 */
use Keel\Core\Csrf;

$areaTitle = $areaTitle ?? 'FairPlate';
$signedInAs = trim((string) ($user['name'] ?? ''));
$signedInAs = $signedInAs !== '' ? $signedInAs : (string) ($user['phone'] ?? $user['email'] ?? '');
?>
<header class="bar">
    <div class="stack stack-0">
        <h1 class="h3"><?= htmlspecialchars($areaTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($signedInAs !== ''): ?>
        <p class="text-sm text-muted"><?= htmlspecialchars($signedInAs, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>
    <?php $themeToggleClass = 'push'; require __DIR__ . '/theme-toggle.php'; ?>
    <form method="POST" action="/logout">
        <?= Csrf::field() ?>
        <button type="submit" class="btn">Sign out</button>
    </form>
</header>
