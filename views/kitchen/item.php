<?php
/**
 * One menu item: its fields, its photo, and its option groups.
 *
 * Options get their own screen because an item with a size group, an extras
 * group and a photo is more than fits in a list row, and because the option
 * prices are the fiddliest thing on the menu to get right.
 */

use EchoDial\Deck\Deck;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;

$kitchenScripts = ['/js/kitchen-sort.js'];
require __DIR__ . '/partials/top.php';

$itemId = (int) $item['id'];
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>

<p><a href="/kitchen/menu" class="btn btn-ghost"><?= Deck::icon('arrow-left') ?> Back to the menu</a></p>

<section class="card">
    <div class="card-header">
        <h2 class="card-title"><?= $escape((string) $item['name']) ?></h2>
        <form method="POST" action="/kitchen/menu/items/<?= $itemId ?>/stock" class="push">
            <?= Csrf::field() ?>
            <input type="hidden" name="back" value="/kitchen/menu/items/<?= $itemId ?>">
            <button type="submit" class="btn <?= (int) $item['in_stock'] === 1 ? 'btn-outline' : 'btn-primary' ?>">
                <?= (int) $item['in_stock'] === 1 ? '86 it' : 'Back on the menu' ?>
            </button>
        </form>
    </div>
    <form method="POST" action="/kitchen/menu/items/<?= $itemId ?>" class="card-body stack stack-4">
        <?= Csrf::field() ?>
        <div class="field-row">
            <div class="field">
                <label class="label" for="name">Name</label>
                <input type="text" id="name" name="name" class="input" value="<?= $escape((string) $item['name']) ?>" required>
            </div>
            <div class="field">
                <label class="label" for="price">Price</label>
                <div class="input-group">
                    <span class="addon">$</span>
                    <input type="text" id="price" name="price" class="input" inputmode="decimal"
                           value="<?= Money::toDollars((int) $item['price_cents']) ?>" required>
                </div>
            </div>
        </div>

        <div class="field">
            <label class="label" for="description">Description</label>
            <textarea id="description" name="description" class="textarea" rows="3"><?= $escape((string) ($item['description'] ?? '')) ?></textarea>
        </div>

        <div class="field">
            <label class="label" for="menu_category_id">Category</label>
            <select id="menu_category_id" name="menu_category_id" class="select">
                <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" <?= (int) $category['id'] === (int) $item['menu_category_id'] ? 'selected' : '' ?>>
                    <?= $escape((string) $category['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <label class="check">
            <input type="checkbox" name="active" value="1" <?= (int) $item['active'] === 1 ? 'checked' : '' ?>>
            <span class="check-text">Show this item on the menu</span>
        </label>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">Save item</button>
        </div>
    </form>
</section>

<section class="card">
    <div class="card-header"><h2 class="card-title">Photo</h2></div>
    <div class="card-body stack stack-3">
        <?php if (($item['photo'] ?? null) !== null): ?>
        <img src="<?= $escape((string) $item['photo']) ?>" alt="<?= $escape((string) $item['name']) ?>"
             class="r-md" style="max-inline-size: 18rem">
        <form method="POST" action="/kitchen/menu/items/<?= $itemId ?>/photo/delete">
            <?= Csrf::field() ?>
            <button type="submit" class="btn"><?= Deck::icon('trash') ?> Remove photo</button>
        </form>
        <?php endif; ?>

        <?php if (!($photosAvailable ?? false)): ?>
        <div class="alert alert-warn">
            <p class="alert-body">Image uploads need PHP's GD extension with WebP support.</p>
        </div>
        <?php endif; ?>

        <form method="POST" action="/kitchen/menu/items/<?= $itemId ?>/photo" enctype="multipart/form-data"
              class="stack stack-3">
            <?= Csrf::field() ?>
            <label class="file">
                <input type="file" name="photo" accept="image/*" <?= ($photosAvailable ?? false) ? '' : 'disabled' ?>>
                <span><?= Deck::icon('camera') ?> Choose a photo</span>
            </label>
            <button type="submit" class="btn btn-primary" <?= ($photosAvailable ?? false) ? '' : 'disabled' ?>>Upload photo</button>
        </form>
    </div>
</section>

<section class="card">
    <div class="card-header"><h2 class="card-title">Option groups</h2></div>
    <div class="card-body stack stack-4">
        <p class="text-sm text-muted">
            A group is one question — "Which size?", "Anything extra?" — and the options are the answers.
            Set a minimum of one to make the question required.
        </p>

        <ul class="stack stack-4 menu-rows" data-sortable="groups">
            <?php foreach ($groups as $group): ?>
            <?php $groupId = (int) $group['id']; ?>
            <li class="card card-flush" data-id="<?= $groupId ?>">
                <div class="card-header">
                    <span class="drag-handle" data-drag aria-hidden="true"><?= Deck::icon('sort') ?></span>
                    <h3 class="h6"><?= $escape((string) $group['name']) ?></h3>
                    <span class="badge push">
                        pick <?= (int) $group['min_select'] ?>–<?= (int) $group['max_select'] ?>
                    </span>
                </div>
                <div class="card-body stack stack-3">
                    <form method="POST" action="/kitchen/menu/groups/<?= $groupId ?>" class="field-row">
                        <?= Csrf::field() ?>
                        <div class="field">
                            <label class="label" for="group-name-<?= $groupId ?>">Question</label>
                            <input type="text" id="group-name-<?= $groupId ?>" name="name" class="input"
                                   value="<?= $escape((string) $group['name']) ?>" required>
                        </div>
                        <div class="field">
                            <label class="label" for="group-min-<?= $groupId ?>">Least</label>
                            <input type="number" id="group-min-<?= $groupId ?>" name="min_select" class="input"
                                   min="0" max="20" value="<?= (int) $group['min_select'] ?>">
                        </div>
                        <div class="field">
                            <label class="label" for="group-max-<?= $groupId ?>">Most</label>
                            <input type="number" id="group-max-<?= $groupId ?>" name="max_select" class="input"
                                   min="1" max="20" value="<?= (int) $group['max_select'] ?>">
                        </div>
                        <div class="field">
                            <label class="label" aria-hidden="true">&nbsp;</label>
                            <button type="submit" class="btn">Save</button>
                        </div>
                    </form>

                    <ul class="list menu-rows" data-sortable="options">
                        <?php foreach ($group['options'] as $option): ?>
                        <?php $optionId = (int) $option['id']; ?>
                        <li class="list-row" data-id="<?= $optionId ?>">
                            <span class="drag-handle" data-drag aria-hidden="true"><?= Deck::icon('sort') ?></span>
                            <form method="POST" action="/kitchen/menu/options/<?= $optionId ?>" class="list-main field-row">
                                <?= Csrf::field() ?>
                                <div class="field">
                                    <label class="sr-only" for="opt-name-<?= $optionId ?>">Option name</label>
                                    <input type="text" id="opt-name-<?= $optionId ?>" name="name" class="input"
                                           value="<?= $escape((string) $option['name']) ?>" required>
                                </div>
                                <div class="field">
                                    <label class="sr-only" for="opt-price-<?= $optionId ?>">Price change</label>
                                    <div class="input-group">
                                        <span class="addon">$</span>
                                        <input type="text" id="opt-price-<?= $optionId ?>" name="price_delta" class="input"
                                               inputmode="decimal"
                                               value="<?= Money::toDollars((int) $option['price_delta_cents']) ?>">
                                    </div>
                                </div>
                                <div class="field">
                                    <label class="check">
                                        <input type="checkbox" name="active" value="1" <?= (int) $option['active'] === 1 ? 'checked' : '' ?>>
                                        <span class="check-text">On</span>
                                    </label>
                                </div>
                                <div class="field">
                                    <button type="submit" class="btn">Save</button>
                                </div>
                            </form>
                            <form method="POST" action="/kitchen/menu/options/<?= $optionId ?>/delete" class="list-trail">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn btn-ghost" aria-label="Delete <?= $escape((string) $option['name']) ?>">
                                    <?= Deck::icon('trash') ?>
                                </button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>

                    <form method="POST" action="/kitchen/menu/groups/<?= $groupId ?>/options" class="field-row">
                        <?= Csrf::field() ?>
                        <div class="field">
                            <label class="label" for="new-opt-<?= $groupId ?>">New option</label>
                            <input type="text" id="new-opt-<?= $groupId ?>" name="name" class="input" placeholder="Large" required>
                        </div>
                        <div class="field">
                            <label class="label" for="new-opt-price-<?= $groupId ?>">Price change</label>
                            <div class="input-group">
                                <span class="addon">$</span>
                                <input type="text" id="new-opt-price-<?= $groupId ?>" name="price_delta" class="input"
                                       inputmode="decimal" value="0.00">
                            </div>
                            <p class="help">Use a minus sign to take money off.</p>
                        </div>
                        <div class="field">
                            <label class="label" aria-hidden="true">&nbsp;</label>
                            <button type="submit" class="btn btn-primary">Add option</button>
                        </div>
                    </form>

                    <form method="POST" action="/kitchen/menu/groups/<?= $groupId ?>/delete"
                          onsubmit="return confirm('Delete this option group and all its options?');">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-danger btn-sm">
                            <?= Deck::icon('trash') ?> Delete group
                        </button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>

        <form method="POST" action="/kitchen/menu/items/<?= $itemId ?>/groups" class="field-row">
            <?= Csrf::field() ?>
            <div class="field">
                <label class="label" for="new-group">New option group</label>
                <input type="text" id="new-group" name="name" class="input" placeholder="Which size?" required>
            </div>
            <div class="field">
                <label class="label" for="new-group-min">Least</label>
                <input type="number" id="new-group-min" name="min_select" class="input" min="0" max="20" value="0">
            </div>
            <div class="field">
                <label class="label" for="new-group-max">Most</label>
                <input type="number" id="new-group-max" name="max_select" class="input" min="1" max="20" value="1">
            </div>
            <div class="field">
                <label class="label" aria-hidden="true">&nbsp;</label>
                <button type="submit" class="btn btn-primary btn-lg">Add group</button>
            </div>
        </form>
    </div>
</section>

<section class="card">
    <form method="POST" action="/kitchen/menu/items/<?= $itemId ?>/delete" class="card-body"
          onsubmit="return confirm('Delete <?= $escape((string) $item['name']) ?> from the menu?');">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-danger"><?= Deck::icon('trash') ?> Delete this item</button>
    </form>
</section>

<?php require __DIR__ . '/partials/bottom.php'; ?>
