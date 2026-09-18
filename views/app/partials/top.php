<?php
/**
 * The top of every customer screen: head, header, flash.
 *
 * Set $title, $heading, $activeNav, $user, $cartCount, $isMember and
 * $deliveryAddress before requiring it — App\CustomerController::shell()
 * returns exactly that. A screen that needs a script of its own lists it in
 * $appScripts before requiring this.
 *
 * The navigation is a bottom tab bar, in partials/bottom.php. That is where a
 * thumb is on a phone held one-handed, which is how this application is used,
 * and it means the top of the screen can be the address the food is going to
 * rather than a row of links.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Address;
use Keel\Core\Csrf;
use Keel\Core\Theme;

$activeNav = $activeNav ?? 'browse';
$flash = $flash ?? null;
$cartCount = (int) ($cartCount ?? 0);
$deliveryAddress = $deliveryAddress ?? null;
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html <?= Deck::htmlAttributes(lang: 'en') ?> <?= Deck::theme(mode: Theme::serverPreference()) ?>>
<head>
<?php require dirname(__DIR__, 2) . '/partials/head.php'; ?>
<link rel="stylesheet" href="/css/app.css">
<?php
// A screen that needs a tag of its own in <head> builds it before requiring
// this — the meta refresh on the checkout waiting room is the only one so far,
// and it has to be here because a refresh in <body> is not a refresh.
?>
<?= $appHeadExtra ?? '' ?>
<?php foreach (($appScripts ?? []) as $appScript): ?>
<script src="<?= $escape($appScript) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="app-body">
    <span id="top" tabindex="-1"></span>

    <main class="container stack stack-5 app-main" style="--container: 40rem">
        <header class="app-header bar gap-2">
            <div class="stack stack-0 min-w-0">
                <a href="/app" class="app-brand no-underline"><?= $escape($heading ?? 'FairPlate') ?></a>
                <?php if ($deliveryAddress !== null): ?>
                <a href="/app/addresses" class="text-sm text-muted truncate no-underline">
                    <?= Deck::icon('map-pin', 'icon icon-sm') ?>
                    <?= $escape(Address::oneLine($deliveryAddress)) ?>
                </a>
                <?php else: ?>
                <a href="/app/addresses" class="text-sm text-brand no-underline">Add a delivery address</a>
                <?php endif; ?>
            </div>

            <?php $themeToggleClass = 'push'; require dirname(__DIR__, 2) . '/partials/theme-toggle.php'; ?>
            <form method="POST" action="/logout">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-icon btn-ghost" aria-label="Sign out"><?= Deck::icon('log-out') ?></button>
            </form>
        </header>

        <?php
        // Deck hides the tab bar from 48rem up, where a bottom bar stops being
        // where a thumb is. The same five destinations, as a row.
        $wideTabs = [
            'browse' => ['label' => 'Order', 'href' => '/app', 'icon' => 'search'],
            'cart' => ['label' => 'Cart', 'href' => '/app/cart', 'icon' => 'receipt'],
            'orders' => ['label' => 'Orders', 'href' => '/app/orders', 'icon' => 'clock'],
            'addresses' => ['label' => 'Addresses', 'href' => '/app/addresses', 'icon' => 'map-pin'],
            'membership' => ['label' => 'Membership', 'href' => '/app/membership', 'icon' => 'star'],
        ];
        ?>
        <nav class="app-wide-nav cluster gap-2" aria-label="FairPlate">
            <?php foreach ($wideTabs as $wideKey => $wideTab): ?>
            <a href="<?= $escape($wideTab['href']) ?>"
               class="btn <?= $wideKey === $activeNav ? 'btn-soft' : 'btn-ghost' ?> nowrap"
               <?= $wideKey === $activeNav ? 'aria-current="page"' : '' ?>>
                <?= Deck::icon($wideTab['icon']) ?>
                <?= $escape($wideTab['label']) ?>
                <?php if ($wideKey === 'cart' && $cartCount > 0): ?>
                <span class="badge badge-brand"><?= $cartCount ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($flash !== null): ?>
        <div class="alert alert-<?= $escape((string) $flash['tone']) ?>" role="status">
            <p class="alert-body"><?= $escape((string) $flash['message']) ?></p>
        </div>
        <?php endif; ?>
