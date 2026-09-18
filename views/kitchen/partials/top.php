<?php
/**
 * The top of every kitchen screen: head, header, nav, flash.
 *
 * Set $title, $activeNav, $user, $restaurant and $restaurants before requiring
 * it — Kitchen\KitchenController::shell() returns exactly that. A screen that
 * needs a script of its own adds it with $kitchenScripts before requiring this.
 *
 * The nav is a row of buttons rather than links so every target clears 44px
 * without anything here having to say so.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Restaurant;
use Keel\Core\Csrf;
use Keel\Core\Theme;

$navItems = [
    'orders' => ['label' => 'Orders', 'href' => '/kitchen', 'icon' => 'receipt'],
    'menu' => ['label' => 'Menu', 'href' => '/kitchen/menu', 'icon' => 'list'],
    'specials' => ['label' => 'Specials', 'href' => '/kitchen/specials', 'icon' => 'tag'],
    'hours' => ['label' => 'Hours', 'href' => '/kitchen/hours', 'icon' => 'clock'],
    'staff' => ['label' => 'Staff', 'href' => '/kitchen/staff', 'icon' => 'users'],
    'month' => ['label' => 'This month', 'href' => '/kitchen/month', 'icon' => 'chart'],
    'onboarding' => ['label' => 'Profile', 'href' => '/kitchen/onboarding', 'icon' => 'home'],
];

$restaurant = $restaurant ?? null;
$restaurants = $restaurants ?? [];
$activeNav = $activeNav ?? 'orders';
$flash = $flash ?? null;
$isPaused = $restaurant !== null && Restaurant::isPaused($restaurant);
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

// Where a form that can be submitted from more than one screen should land
// again. The path only: the controllers refuse anything with a query on it.
$kitchenPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/kitchen'), PHP_URL_PATH) ?: '/kitchen');
?>
<!DOCTYPE html>
<html <?= Deck::htmlAttributes(lang: 'en') ?> <?= Deck::theme(mode: Theme::serverPreference()) ?>>
<head>
<?php require dirname(__DIR__, 2) . '/partials/head.php'; ?>
<link rel="stylesheet" href="/css/kitchen.css">
<?php foreach (($kitchenScripts ?? []) as $script): ?>
<script src="<?= $escape($script) ?>" defer></script>
<?php endforeach; ?>
</head>
<body>
    <span id="top" tabindex="-1"></span>

    <main class="container stack stack-5" style="--container: 96rem">
        <header class="bar wrap gap-3">
            <div class="stack stack-0">
                <h1 class="h4"><?= $escape($restaurant['name'] ?? 'FairPlate Kitchen') ?></h1>
                <p class="text-sm text-muted">
                    <?= $escape($title ?? 'Kitchen') ?>
                    <?php if ($restaurant !== null): ?>
                    · <?= $escape((string) $restaurant['status']) ?>
                    <?php endif; ?>
                </p>
            </div>

            <?php if (count($restaurants) > 1): ?>
            <form method="POST" action="/kitchen/restaurant/select" class="cluster gap-2">
                <?= Csrf::field() ?>
                <label class="sr-only" for="restaurant-select">Restaurant</label>
                <select id="restaurant-select" name="restaurant_id" class="select">
                    <?php foreach ($restaurants as $restaurantOption): ?>
                    <option value="<?= (int) $restaurantOption['id'] ?>" <?= (int) $restaurantOption['id'] === (int) ($restaurant['id'] ?? 0) ? 'selected' : '' ?>>
                        <?= $escape((string) $restaurantOption['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn">Switch</button>
            </form>
            <?php endif; ?>

            <?php $themeToggleClass = 'push'; require dirname(__DIR__, 2) . '/partials/theme-toggle.php'; ?>
            <form method="POST" action="/logout">
                <?= Csrf::field() ?>
                <button type="submit" class="btn">Sign out</button>
            </form>
        </header>

        <nav class="scroller gap-2" aria-label="Kitchen">
            <?php foreach ($navItems as $navKey => $navItem): ?>
            <a href="<?= $escape($navItem['href']) ?>"
               class="btn <?= $navKey === $activeNav ? 'btn-soft' : 'btn-ghost' ?> nowrap"
               <?= $navKey === $activeNav ? 'aria-current="page"' : '' ?>>
                <?= Deck::icon($navItem['icon']) ?> <?= $escape($navItem['label']) ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($flash !== null): ?>
        <div class="alert alert-<?= $escape((string) $flash['tone']) ?>" role="status">
            <p class="alert-body"><?= $escape((string) $flash['message']) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($isPaused): ?>
        <div class="alert alert-warn" role="status">
            <div class="alert-body bar wrap gap-3">
                <span>
                    <strong>Orders are paused.</strong>
                    <?php $left = Restaurant::pauseMinutesLeft($restaurant); ?>
                    <?= $left === null ? 'Customers cannot order until you resume.' : 'Resuming in ' . (int) $left . ' min.' ?>
                </span>
                <form method="POST" action="/kitchen/resume" class="push">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="back" value="<?= $escape($kitchenPath) ?>">
                    <button type="submit" class="btn btn-primary">Take orders again</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
