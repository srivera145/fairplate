<?php
/**
 * One item: its options, a quantity and a note.
 *
 * This is the whole of the item sheet, and also the whole of the item page. The
 * sheet fetches it; the page requires it. One file, so the two can never offer
 * different choices.
 *
 * The min/max rules are written onto the fieldsets as data attributes for
 * app-menu.js to read. They are a courtesy — CartService::validateSelection()
 * re-checks every one of them on the post, and that is the copy that decides.
 */

use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;

$groups = $groups ?? [];
$errors = $errors ?? [];
$line = $line ?? null;
$chosen = array_map('intval', (array) ($line['option_ids'] ?? []));
$quantity = max(1, (int) ($line['quantity'] ?? 1));
$notes = (string) ($line['notes'] ?? '');
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$itemId = (int) $item['id'];
?>
<form method="POST" action="/app/cart/items" class="stack stack-4" data-item-form>
    <?= Csrf::field() ?>
    <input type="hidden" name="menu_item_id" value="<?= $itemId ?>">

    <?php if ($errors !== []): ?>
    <div class="alert alert-bad" role="alert">
        <div class="alert-body stack stack-1">
            <?php foreach ($errors as $error): ?>
            <p><?= $escape((string) $error) ?></p>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="stack stack-1">
        <h3 class="h5"><?= $escape((string) $item['name']) ?></h3>
        <?php if (trim((string) ($item['description'] ?? '')) !== ''): ?>
        <p class="text-sm text-muted"><?= $escape((string) $item['description']) ?></p>
        <?php endif; ?>
        <p class="nums fw-semi"><?= $escape(Money::usd((int) $item['price_cents'])) ?></p>
    </div>

    <?php foreach ($groups as $group): ?>
    <?php
    $groupId = (int) $group['id'];
    $required = (int) $group['required'] === 1;
    $min = max((int) $group['min_select'], $required ? 1 : 0);
    $max = (int) $group['max_select'];
    // One choice at most is a radio group; anything else is checkboxes. The
    // control says what the rule is before anybody reads the rule.
    $single = $max === 1;
    ?>
    <fieldset class="fieldset" data-option-group="<?= $groupId ?>" data-min="<?= $min ?>" data-max="<?= $max ?>">
        <legend>
            <?= $escape((string) $group['name']) ?>
            <?php if ($required || $min > 0): ?>
            <span class="required">Required</span>
            <?php else: ?>
            <span class="optional">Optional</span>
            <?php endif; ?>
        </legend>

        <p class="help" data-group-rule>
            <?php if ($min > 0 && $max > 0 && $min === $max): ?>
            Choose <?= $min ?>.
            <?php elseif ($min > 0 && $max > 0): ?>
            Choose <?= $min ?> to <?= $max ?>.
            <?php elseif ($min > 0): ?>
            Choose at least <?= $min ?>.
            <?php elseif ($max > 0): ?>
            Choose up to <?= $max ?>.
            <?php else: ?>
            Choose as many as you like.
            <?php endif; ?>
        </p>

        <div class="stack stack-1">
            <?php foreach ($group['options'] as $option): ?>
            <?php $optionId = (int) $option['id']; ?>
            <label class="check option-row">
                <input type="<?= $single ? 'radio' : 'checkbox' ?>"
                       name="options[<?= $single ? $groupId : $optionId ?>]"
                       value="<?= $optionId ?>"
                       <?= in_array($optionId, $chosen, true) ? 'checked' : '' ?>>
                <span class="check-text"><?= $escape((string) $option['name']) ?></span>
                <?php if ((int) $option['price_delta_cents'] !== 0): ?>
                <span class="option-price">
                    <?= (int) $option['price_delta_cents'] > 0 ? '+' : '' ?><?= $escape(Money::usd((int) $option['price_delta_cents'])) ?>
                </span>
                <?php endif; ?>
            </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <?php endforeach; ?>

    <div class="field">
        <label class="label" for="notes-<?= $itemId ?>">Special instructions <span class="optional">Optional</span></label>
        <textarea id="notes-<?= $itemId ?>" name="notes" class="textarea" rows="2" maxlength="255"
                  placeholder="No onions, please"><?= $escape($notes) ?></textarea>
        <p class="help">The kitchen sees this on the ticket.</p>
    </div>

    <div class="field">
        <label class="label" for="quantity-<?= $itemId ?>">How many?</label>
        <?php
        // Deck's number control. deck-extras.js wires the two buttons and the
        // press-and-hold; with no script at all the input in the middle is a
        // plain number field that still posts, which is the whole reason the
        // control is built around a real input rather than around the buttons.
        ?>
        <div class="number">
            <button type="button" data-step="-1" aria-label="Fewer">−</button>
            <input type="number" id="quantity-<?= $itemId ?>" name="quantity"
                   value="<?= $quantity ?>" min="1" max="25" step="1" inputmode="numeric">
            <button type="button" data-step="1" aria-label="More">+</button>
        </div>
    </div>

    <button type="submit" class="btn btn-primary btn-lg btn-block" data-item-submit>Add to cart</button>
</form>
