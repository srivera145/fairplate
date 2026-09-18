<?php
/**
 * The bottom of every customer screen: the tab bar, and the closing tags.
 *
 * Five destinations is the most a tab bar can hold at 360px and still clear
 * 44px each, which is exactly what the customer app has. The cart carries a
 * count because it is the one tab whose state matters before you tap it.
 */

use EchoDial\Deck\Deck;

$activeNav = $activeNav ?? 'browse';
$cartCount = (int) ($cartCount ?? 0);
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$tabs = [
    'browse' => ['label' => 'Order', 'href' => '/app', 'icon' => 'search'],
    'cart' => ['label' => 'Cart', 'href' => '/app/cart', 'icon' => 'receipt'],
    'orders' => ['label' => 'Orders', 'href' => '/app/orders', 'icon' => 'clock'],
    'addresses' => ['label' => 'Addresses', 'href' => '/app/addresses', 'icon' => 'map-pin'],
    'membership' => ['label' => 'Member', 'href' => '/app/membership', 'icon' => 'star'],
];
?>
    </main>

    <nav class="tabbar" aria-label="FairPlate">
        <?php foreach ($tabs as $key => $tab): ?>
        <a href="<?= $escape($tab['href']) ?>"
           class="tabbar-item <?= $key === $activeNav ? 'is-current' : '' ?>"
           <?= $key === $activeNav ? 'aria-current="page"' : '' ?>>
            <?php if ($key === 'cart' && $cartCount > 0): ?>
            <span class="with-indicator">
                <?= Deck::icon($tab['icon']) ?>
                <span class="indicator-badge indicator-badge-brand"><?= $cartCount ?></span>
            </span>
            <?php else: ?>
            <?= Deck::icon($tab['icon']) ?>
            <?php endif; ?>
            <span><?= $escape($tab['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <?php require dirname(__DIR__, 2) . '/partials/back-to-top.php'; ?>
</body>
</html>
