<?php
/**
 * Specials, each with a preview of the card a customer will see.
 *
 * The preview is rendered by SpecialsController::describe(), which is the same
 * function the customer menu calls in phase 4. A preview built from its own
 * copy of the wording is a preview that will eventually be wrong.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Special;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;

$kitchenScripts = ['/js/kitchen-specials.js'];
require __DIR__ . '/partials/top.php';

$specials = $specials ?? [];
$items = $items ?? [];
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$weekdays = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

$selectedDays = static function (array $special): array {
    $days = $special['days'] ?? null;

    if (is_string($days)) {
        $days = json_decode($days, true);
    }

    return is_array($days) ? array_map('intval', $days) : [];
};
?>

<section class="card">
    <div class="card-header"><h2 class="card-title">Add a special</h2></div>
    <form method="POST" action="/kitchen/specials" class="card-body stack stack-4" data-special-form>
        <?= Csrf::field() ?>

        <div class="field">
            <label class="label" for="title">Title</label>
            <input type="text" id="title" name="title" class="input" placeholder="Taco Tuesday"
                   data-special-title required>
        </div>

        <div class="field">
            <label class="label" for="description">Description <span class="optional">Optional</span></label>
            <input type="text" id="description" name="description" class="input"
                   placeholder="All street tacos, 20% off, all day Tuesday." data-special-description>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="type">Kind</label>
                <select id="type" name="type" class="select" data-special-type>
                    <option value="<?= Special::TYPE_PERCENT ?>">Percent off</option>
                    <option value="<?= Special::TYPE_AMOUNT ?>">Dollars off</option>
                    <option value="<?= Special::TYPE_PRICE ?>">Fixed price for one item</option>
                </select>
            </div>
            <div class="field">
                <label class="label" for="value">Amount</label>
                <div class="input-group">
                    <span class="addon" data-special-unit>%</span>
                    <input type="text" id="value" name="value" class="input" inputmode="decimal"
                           placeholder="20" data-special-value required>
                </div>
            </div>
        </div>

        <div class="field">
            <label class="label" for="menu_item_id">Item <span class="optional">Optional for a percent or dollar special</span></label>
            <select id="menu_item_id" name="menu_item_id" class="select" data-special-item>
                <option value="0">The whole order</option>
                <?php foreach ($items as $item): ?>
                <option value="<?= (int) $item['id'] ?>"><?= $escape((string) $item['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <fieldset class="fieldset">
            <legend>Days <span class="optional">Leave all clear for every day</span></legend>
            <div class="cluster gap-3 wrap">
                <?php foreach ($weekdays as $number => $label): ?>
                <label class="check">
                    <input type="checkbox" name="days[]" value="<?= $number ?>">
                    <span class="check-text"><?= $label ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="field-row">
            <div class="field">
                <label class="label" for="start_time">Starts <span class="optional">Optional</span></label>
                <input type="time" id="start_time" name="start_time" class="input">
            </div>
            <div class="field">
                <label class="label" for="end_time">Ends <span class="optional">Optional</span></label>
                <input type="time" id="end_time" name="end_time" class="input">
            </div>
        </div>

        <label class="check">
            <input type="checkbox" name="active" value="1" checked>
            <span class="check-text">Run this special now</span>
        </label>

        <div class="stack stack-2">
            <h3 class="h6">How customers will see it</h3>
            <div class="special-preview stack stack-1" data-special-preview>
                <strong data-preview-title>Your special</strong>
                <span class="text-brand fw-semi" data-preview-line>20% off your order</span>
                <span class="text-sm text-muted" data-preview-description></span>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">Add special</button>
        </div>
    </form>
</section>

<?php if ($specials === []): ?>
<section class="card">
    <div class="empty">
        <span class="empty-art"><?= Deck::icon('tag', 'icon icon-lg') ?></span>
        <h2 class="empty-title">No specials yet</h2>
        <p>A special shows on your page in the app. It costs you nothing to run one.</p>
    </div>
</section>
<?php endif; ?>

<?php foreach ($specials as $special): ?>
<?php $specialId = (int) $special['id']; $days = $selectedDays($special); ?>
<section class="card">
    <div class="card-header">
        <h2 class="card-title"><?= $escape((string) $special['title']) ?></h2>
        <span class="badge <?= (int) $special['active'] === 1 ? 'badge-good' : '' ?> push">
            <?= (int) $special['active'] === 1 ? 'Running' : 'Off' ?>
        </span>
    </div>
    <div class="card-body stack stack-4">
        <div class="special-preview stack stack-1">
            <strong><?= $escape((string) $special['title']) ?></strong>
            <span class="text-brand fw-semi"><?= $escape((string) $special['preview']) ?></span>
            <?php if (($special['description'] ?? null) !== null): ?>
            <span class="text-sm text-muted"><?= $escape((string) $special['description']) ?></span>
            <?php endif; ?>
            <span class="text-sm text-muted"><?= $escape((string) $special['day_labels']) ?></span>
        </div>

        <details>
            <summary class="btn btn-ghost cursor-pointer"><?= Deck::icon('edit') ?> Edit</summary>
            <form method="POST" action="/kitchen/specials/<?= $specialId ?>" class="stack stack-4 mt-3">
                <?= Csrf::field() ?>
                <div class="field">
                    <label class="label" for="title-<?= $specialId ?>">Title</label>
                    <input type="text" id="title-<?= $specialId ?>" name="title" class="input"
                           value="<?= $escape((string) $special['title']) ?>" required>
                </div>
                <div class="field">
                    <label class="label" for="desc-<?= $specialId ?>">Description</label>
                    <input type="text" id="desc-<?= $specialId ?>" name="description" class="input"
                           value="<?= $escape((string) ($special['description'] ?? '')) ?>">
                </div>
                <div class="field-row">
                    <div class="field">
                        <label class="label" for="type-<?= $specialId ?>">Kind</label>
                        <select id="type-<?= $specialId ?>" name="type" class="select">
                            <?php foreach ([
                                Special::TYPE_PERCENT => 'Percent off',
                                Special::TYPE_AMOUNT => 'Dollars off',
                                Special::TYPE_PRICE => 'Fixed price for one item',
                            ] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= (string) $special['type'] === $value ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label" for="value-<?= $specialId ?>">Amount</label>
                        <input type="text" id="value-<?= $specialId ?>" name="value" class="input" inputmode="decimal"
                               value="<?= (string) $special['type'] === Special::TYPE_PERCENT
                                   ? $escape(Money::percentFromRate((string) ($special['value_pct'] ?? '0')))
                                   : $escape(Money::toDollars((int) ($special['value_cents'] ?? 0))) ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="item-<?= $specialId ?>">Item</label>
                    <select id="item-<?= $specialId ?>" name="menu_item_id" class="select">
                        <option value="0">The whole order</option>
                        <?php foreach ($items as $item): ?>
                        <option value="<?= (int) $item['id'] ?>" <?= (int) ($special['menu_item_id'] ?? 0) === (int) $item['id'] ? 'selected' : '' ?>>
                            <?= $escape((string) $item['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <fieldset class="fieldset">
                    <legend>Days</legend>
                    <div class="cluster gap-3 wrap">
                        <?php foreach ($weekdays as $number => $label): ?>
                        <label class="check">
                            <input type="checkbox" name="days[]" value="<?= $number ?>" <?= in_array($number, $days, true) ? 'checked' : '' ?>>
                            <span class="check-text"><?= $label ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <div class="field-row">
                    <div class="field">
                        <label class="label" for="start-<?= $specialId ?>">Starts</label>
                        <input type="time" id="start-<?= $specialId ?>" name="start_time" class="input"
                               value="<?= $escape(substr((string) ($special['start_time'] ?? ''), 0, 5)) ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="end-<?= $specialId ?>">Ends</label>
                        <input type="time" id="end-<?= $specialId ?>" name="end_time" class="input"
                               value="<?= $escape(substr((string) ($special['end_time'] ?? ''), 0, 5)) ?>">
                    </div>
                </div>
                <label class="check">
                    <input type="checkbox" name="active" value="1" <?= (int) $special['active'] === 1 ? 'checked' : '' ?>>
                    <span class="check-text">Run this special</span>
                </label>
                <div class="cluster gap-2">
                    <button type="submit" class="btn btn-primary">Save special</button>
                </div>
            </form>
            <form method="POST" action="/kitchen/specials/<?= $specialId ?>/delete" class="mt-3"
                  onsubmit="return confirm('Delete this special?');">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-danger"><?= Deck::icon('trash') ?> Delete</button>
            </form>
        </details>
    </div>
</section>
<?php endforeach; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
