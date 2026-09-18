<?php
/**
 * The bottom of every driver screen: the tab bar, and the closing tags.
 *
 * Three destinations, because there are three. A driver app with a row of tabs
 * is an app somebody has to read while driving; this one has a home screen, a
 * page saying what the day paid, and the form nobody opens twice.
 */

use EchoDial\Deck\Deck;

$activeNav = $activeNav ?? 'home';
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$tabs = [
    'home' => ['label' => 'Drive', 'href' => '/drive', 'icon' => 'car'],
    'earnings' => ['label' => 'Earnings', 'href' => '/drive/earnings', 'icon' => 'dollar'],
    'account' => ['label' => 'Account', 'href' => '/drive/onboarding', 'icon' => 'user'],
];
?>
    </main>

    <nav class="tabbar" aria-label="FairPlate Drive">
        <?php foreach ($tabs as $key => $tab): ?>
        <a href="<?= $escape($tab['href']) ?>"
           class="tabbar-item <?= $key === $activeNav ? 'is-current' : '' ?>"
           <?= $key === $activeNav ? 'aria-current="page"' : '' ?>>
            <?= Deck::icon($tab['icon']) ?>
            <span><?= $escape($tab['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
</body>
</html>
