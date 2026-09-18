<?php
/**
 * Categories and the items inside them.
 *
 * Ordering has two controls that do the same thing: a drag handle, and a pair
 * of arrows. The arrows are not a fallback — on a tablet on a counter they are
 * the faster of the two, and they are the only one a keyboard can use.
 */

use EchoDial\Deck\Deck;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;

$kitchenScripts = ['/js/kitchen-sort.js'];
require __DIR__ . '/partials/top.php';

$categories = $categories ?? [];
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>

<section class="card">
    <div class="card-header">
        <h2 class="card-title">Add a category</h2>
    </div>
    <form method="POST" action="/kitchen/menu/categories" class="card-body field-row">
        <?= Csrf::field() ?>
        <div class="field">
            <label class="label" for="new-category">Name</label>
            <input type="text" id="new-category" name="name" class="input" placeholder="Tacos" required>
        </div>
        <div class="field">
            <label class="label" for="new-category-desc">Description <span class="optional">Optional</span></label>
            <input type="text" id="new-category-desc" name="description" class="input">
        </div>
        <div class="field">
            <label class="label" aria-hidden="true">&nbsp;</label>
            <button type="submit" class="btn btn-primary btn-lg"><?= Deck::icon('plus') ?> Add category</button>
        </div>
    </form>
</section>

<?php if ($categories === []): ?>
<section class="card">
    <div class="empty">
        <span class="empty-art"><?= Deck::icon('list', 'icon icon-lg') ?></span>
        <h2 class="empty-title">Your menu is empty</h2>
        <p>Start with a category — Tacos, Sides, Drinks — then add items to it.</p>
    </div>
</section>
<?php endif; ?>

<ul class="stack stack-5 menu-rows" data-sortable="categories">
<?php foreach ($categories as $category): ?>
<?php $categoryId = (int) $category['id']; ?>
<li class="card" data-id="<?= $categoryId ?>">
    <div class="card-header">
        <span class="drag-handle" data-drag aria-hidden="true"><?= Deck::icon('sort') ?></span>
        <h2 class="card-title"><?= $escape((string) $category['name']) ?></h2>
        <?php if ((int) $category['active'] === 0): ?>
        <span class="badge badge-warn">Hidden</span>
        <?php endif; ?>
        <span class="push sort-buttons">
            <?php foreach (['up' => 'chevron-up', 'down' => 'chevron-down'] as $direction => $icon): ?>
            <form method="POST" action="/kitchen/menu/categories/<?= $categoryId ?>/move">
                <?= Csrf::field() ?>
                <input type="hidden" name="direction" value="<?= $direction ?>">
                <input type="hidden" name="back" value="/kitchen/menu">
                <button type="submit" class="btn btn-ghost" aria-label="Move <?= $escape((string) $category['name']) ?> <?= $direction ?>">
                    <?= Deck::icon($icon) ?>
                </button>
            </form>
            <?php endforeach; ?>
        </span>
    </div>

    <div class="card-body stack stack-4">
        <details>
            <summary class="btn btn-ghost cursor-pointer"><?= Deck::icon('edit') ?> Edit category</summary>
            <form method="POST" action="/kitchen/menu/categories/<?= $categoryId ?>" class="stack stack-3 mt-3">
                <?= Csrf::field() ?>
                <div class="field">
                    <label class="label" for="cat-name-<?= $categoryId ?>">Name</label>
                    <input type="text" id="cat-name-<?= $categoryId ?>" name="name" class="input"
                           value="<?= $escape((string) $category['name']) ?>" required>
                </div>
                <div class="field">
                    <label class="label" for="cat-desc-<?= $categoryId ?>">Description</label>
                    <input type="text" id="cat-desc-<?= $categoryId ?>" name="description" class="input"
                           value="<?= $escape((string) ($category['description'] ?? '')) ?>">
                </div>
                <label class="check">
                    <input type="checkbox" name="active" value="1" <?= (int) $category['active'] === 1 ? 'checked' : '' ?>>
                    <span class="check-text">Show this category to customers</span>
                </label>
                <div class="cluster gap-2">
                    <button type="submit" class="btn btn-primary">Save category</button>
                </div>
            </form>
            <form method="POST" action="/kitchen/menu/categories/<?= $categoryId ?>/delete" class="mt-3"
                  onsubmit="return confirm('Delete this category and its <?= count($category['items']) ?> item(s)?');">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-danger">
                    <?= Deck::icon('trash') ?> Delete category and its items
                </button>
            </form>
        </details>

        <ul class="list menu-rows" data-sortable="items">
            <?php foreach ($category['items'] as $item): ?>
            <?php $itemId = (int) $item['id']; ?>
            <li class="list-row" data-id="<?= $itemId ?>">
                <span class="drag-handle" data-drag aria-hidden="true"><?= Deck::icon('sort') ?></span>
                <span class="list-main">
                    <a class="list-title" href="/kitchen/menu/items/<?= $itemId ?>"><?= $escape((string) $item['name']) ?></a>
                    <span class="list-sub">
                        $<?= Money::toDollars((int) $item['price_cents']) ?>
                        <?php if ((int) $item['in_stock'] === 0): ?>
                        · <span class="text-bad">86ed</span>
                        <?php endif; ?>
                        <?php if ((int) $item['active'] === 0): ?>
                        · hidden
                        <?php endif; ?>
                    </span>
                </span>
                <span class="list-trail cluster gap-2">
                    <span class="sort-buttons">
                        <?php foreach (['up' => 'chevron-up', 'down' => 'chevron-down'] as $direction => $icon): ?>
                        <form method="POST" action="/kitchen/menu/items/<?= $itemId ?>/move">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="direction" value="<?= $direction ?>">
                            <input type="hidden" name="back" value="/kitchen/menu">
                            <button type="submit" class="btn btn-ghost" aria-label="Move <?= $escape((string) $item['name']) ?> <?= $direction ?>">
                                <?= Deck::icon($icon) ?>
                            </button>
                        </form>
                        <?php endforeach; ?>
                    </span>
                    <form method="POST" action="/kitchen/menu/items/<?= $itemId ?>/stock">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="back" value="/kitchen/menu">
                        <button type="submit" class="btn <?= (int) $item['in_stock'] === 1 ? 'btn-outline' : 'btn-primary' ?>">
                            <?= (int) $item['in_stock'] === 1 ? '86 it' : 'Back on' ?>
                        </button>
                    </form>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>

        <details>
            <summary class="btn btn-ghost cursor-pointer"><?= Deck::icon('plus') ?> Add an item to <?= $escape((string) $category['name']) ?></summary>
            <form method="POST" action="/kitchen/menu/items" class="stack stack-3 mt-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="menu_category_id" value="<?= $categoryId ?>">
                <div class="field-row">
                    <div class="field">
                        <label class="label" for="item-name-<?= $categoryId ?>">Item name</label>
                        <input type="text" id="item-name-<?= $categoryId ?>" name="name" class="input" required>
                    </div>
                    <div class="field">
                        <label class="label" for="item-price-<?= $categoryId ?>">Price</label>
                        <div class="input-group">
                            <span class="addon">$</span>
                            <input type="text" id="item-price-<?= $categoryId ?>" name="price" class="input"
                                   inputmode="decimal" placeholder="12.50" required>
                        </div>
                        <p class="help">Your in-store price. FairPlate never adds to it.</p>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="item-desc-<?= $categoryId ?>">Description <span class="optional">Optional</span></label>
                    <textarea id="item-desc-<?= $categoryId ?>" name="description" class="textarea" rows="2"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-lg">Add item</button>
            </form>
        </details>
    </div>
</li>
<?php endforeach; ?>
</ul>

<?php require __DIR__ . '/partials/bottom.php'; ?>
