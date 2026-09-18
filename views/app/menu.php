<?php
/**
 * One restaurant's menu.
 *
 * Specials sit above the food, because a special is the reason somebody opened
 * this page. The category tabs stick to the top of the viewport and jump to
 * real anchors, so they work with no JavaScript and keep working when the page
 * is long.
 *
 * Each item is a link to its own page. app-menu.js turns that tap into a bottom
 * sheet where it can; without it, the link goes to the page and the same form
 * posts the same way.
 */

use EchoDial\Deck\Deck;
use Keel\App\Services\Pricing\Money;

$appScripts = ['/js/app-menu.js'];
$deckExtras = true;
require __DIR__ . '/partials/top.php';

$categories = $categories ?? [];
$specials = $specials ?? [];
$specialItemIds = $specialItemIds ?? [];
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$slug = (string) $restaurant['slug'];
?>

<section class="stack stack-2">
    <div class="bar gap-2 wrap">
        <h1 class="h4"><?= $escape((string) $restaurant['name']) ?></h1>
        <span class="badge <?= $isOpen ? 'badge-good' : 'badge-warn' ?> push">
            <?= $isOpen ? 'Open now' : $escape((string) $statusLabel) ?>
        </span>
    </div>
    <p class="text-sm text-muted">
        <?= $escape((string) $restaurant['line1']) ?>, <?= $escape((string) $restaurant['city']) ?>
    </p>
</section>

<?php if (!empty($outOfZone)): ?>
<div class="alert alert-warn" role="status">
    <?= Deck::icon('alert-triangle') ?>
    <div class="alert-body">
        <p class="alert-title">Not in our delivery area yet</p>
        <p>This restaurant does not reach your delivery address. You can look, but checkout will ask you for another address.</p>
    </div>
</div>
<?php endif; ?>

<?php if (!$isOpen): ?>
<div class="alert alert-info" role="status">
    <?= Deck::icon('clock') ?>
    <p class="alert-body"><?= $escape((string) $statusLabel) ?>. You can browse now and order when they open.</p>
</div>
<?php endif; ?>

<?php if ($specials !== []): ?>
<section class="card card-brand">
    <div class="card-body stack stack-3">
        <h2 class="h6"><?= Deck::icon('tag', 'icon icon-sm') ?> Running now</h2>
        <ul class="stack stack-2">
            <?php foreach ($specials as $special): ?>
            <li class="stack stack-0">
                <span class="fw-semi"><?= $escape((string) $special['title']) ?></span>
                <span class="text-sm"><?= $escape((string) $special['line']) ?></span>
                <?php if (trim((string) ($special['description'] ?? '')) !== ''): ?>
                <span class="text-sm text-muted"><?= $escape((string) $special['description']) ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>

<?php if ($categories === []): ?>
<section class="card">
    <div class="empty">
        <span class="empty-art"><?= Deck::icon('list', 'icon icon-lg') ?></span>
        <h2 class="empty-title">No menu yet</h2>
        <p class="text-muted">This kitchen has not published its menu.</p>
    </div>
</section>
<?php else: ?>

<nav class="menu-tabs scroller gap-2" aria-label="Menu categories">
    <?php foreach ($categories as $index => $category): ?>
    <a href="#<?= $escape((string) $category['anchor']) ?>"
       class="btn btn-sm <?= $index === 0 ? 'btn-soft' : 'btn-ghost' ?> nowrap"
       data-menu-tab="<?= $escape((string) $category['anchor']) ?>">
        <?= $escape((string) $category['name']) ?>
    </a>
    <?php endforeach; ?>
</nav>

<?php foreach ($categories as $category): ?>
<section class="menu-section stack stack-3" id="<?= $escape((string) $category['anchor']) ?>">
    <div class="stack stack-0">
        <h2 class="h5"><?= $escape((string) $category['name']) ?></h2>
        <?php if (trim((string) ($category['description'] ?? '')) !== ''): ?>
        <p class="text-sm text-muted"><?= $escape((string) $category['description']) ?></p>
        <?php endif; ?>
    </div>

    <ul class="stack stack-2">
        <?php foreach ($category['items'] as $item): ?>
        <?php
        $itemId = (int) $item['id'];
        $inStock = (int) $item['in_stock'] === 1;
        $hasSpecial = in_array($itemId, $specialItemIds, true);
        ?>
        <li class="card">
            <?php if ($inStock): ?>
            <a class="menu-row" href="/app/r/<?= $escape($slug) ?>/items/<?= $itemId ?>" data-item-link="<?= $itemId ?>">
            <?php else: ?>
            <div class="menu-row menu-row-out" aria-disabled="true">
            <?php endif; ?>

                <span class="stack stack-1">
                    <span class="fw-semi">
                        <?= $escape((string) $item['name']) ?>
                        <?php if ($hasSpecial): ?>
                        <span class="badge badge-brand">Special</span>
                        <?php endif; ?>
                        <?php if (!$inStock): ?>
                        <span class="badge">Sold out</span>
                        <?php endif; ?>
                    </span>
                    <?php if (trim((string) ($item['description'] ?? '')) !== ''): ?>
                    <span class="text-sm text-muted clamp-2"><?= $escape((string) $item['description']) ?></span>
                    <?php endif; ?>
                    <span class="text-sm nums"><?= $escape(Money::usd((int) $item['price_cents'])) ?></span>
                </span>

                <?php if (($item['photo'] ?? null) !== null): ?>
                <img class="menu-row-photo" src="<?= $escape((string) $item['photo']) ?>" alt="" loading="lazy">
                <?php endif; ?>

            <?php if ($inStock): ?>
            </a>
            <?php else: ?>
            </div>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endforeach; ?>

<?php endif; ?>

<?php
// The sheet app-menu.js fills. Empty in the markup, because its contents come
// from the item's own page and there is no second copy of them here.
?>
<dialog class="sheet item-sheet" id="item-sheet" aria-labelledby="item-sheet-title">
    <div class="sheet-grip"></div>
    <div class="sheet-header">
        <h2 class="sheet-title" id="item-sheet-title">Add to cart</h2>
        <button type="button" class="btn btn-icon btn-ghost push" data-sheet-close aria-label="Close">
            <?= Deck::icon('x') ?>
        </button>
    </div>
    <div class="sheet-body" data-sheet-body>
        <p class="text-muted">Loading…</p>
    </div>
</dialog>

<?php require __DIR__ . '/partials/bottom.php'; ?>
