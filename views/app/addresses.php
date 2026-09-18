<?php
/**
 * The address book, and the form that adds to it.
 *
 * The form is one screen, not a wizard: five fields and a save. The zone check
 * happens on the server after geocoding, so the only honest place to show its
 * answer is above this form, next to what was typed.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Address;
use Keel\Core\Csrf;

require __DIR__ . '/partials/top.php';

$addresses = $addresses ?? [];
$editing = $editing ?? null;
$errors = $errors ?? [];
$old = $old ?? [];
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$value = static fn (string $key): string => $escape((string) ($old[$key] ?? ''));

$formAction = $editing === null ? '/app/addresses' : '/app/addresses/' . (int) $editing['id'];
?>

<?php if ($addresses !== []): ?>
<section class="stack stack-3">
    <h2 class="h6 text-muted">Saved addresses</h2>
    <ul class="stack stack-2">
        <?php foreach ($addresses as $address): ?>
        <li class="card">
            <div class="card-body stack stack-3">
                <div class="bar gap-2">
                    <span class="fw-semi"><?= $escape((string) $address['label']) ?></span>
                    <?php if ((int) $address['is_default'] === 1): ?>
                    <span class="badge badge-brand push">Default</span>
                    <?php endif; ?>
                </div>
                <p class="text-sm text-muted"><?= $escape(Address::oneLine($address)) ?></p>
                <?php if (trim((string) ($address['instructions'] ?? '')) !== ''): ?>
                <p class="text-sm text-muted">“<?= $escape((string) $address['instructions']) ?>”</p>
                <?php endif; ?>

                <div class="cluster gap-2">
                    <a class="btn btn-sm" href="/app/addresses/<?= (int) $address['id'] ?>/edit">Edit</a>

                    <?php if ((int) $address['is_default'] !== 1): ?>
                    <form method="POST" action="/app/addresses/<?= (int) $address['id'] ?>/default">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-sm">Make default</button>
                    </form>
                    <?php endif; ?>

                    <form method="POST" action="/app/addresses/<?= (int) $address['id'] ?>/delete" class="push">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-ghost text-bad">Delete</button>
                    </form>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="card" id="address-form">
    <div class="card-header">
        <h2 class="card-title"><?= $editing === null ? 'Add an address' : 'Edit address' ?></h2>
    </div>

    <form method="POST" action="<?= $escape($formAction) ?>" class="card-body stack stack-4">
        <?= Csrf::field() ?>

        <?php if ($errors !== []): ?>
        <div class="alert alert-bad" role="alert">
            <?= Deck::icon('alert-triangle') ?>
            <div class="alert-body stack stack-1">
                <?php foreach ($errors as $error): ?>
                <p><?= $escape((string) $error) ?></p>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="field">
            <label class="label" for="label">Name this address</label>
            <input type="text" id="label" name="label" class="input" maxlength="60"
                   value="<?= $value('label') ?>" placeholder="Home" autocomplete="off">
        </div>

        <div class="field">
            <label class="label" for="line1">Street address</label>
            <input type="text" id="line1" name="line1" class="input" maxlength="255" required
                   value="<?= $value('line1') ?>" placeholder="415 N Monroe St" autocomplete="address-line1">
        </div>

        <div class="field">
            <label class="label" for="line2">Apartment or suite <span class="optional">Optional</span></label>
            <input type="text" id="line2" name="line2" class="input" maxlength="255"
                   value="<?= $value('line2') ?>" autocomplete="address-line2">
        </div>

        <div class="field">
            <label class="label" for="city">City</label>
            <input type="text" id="city" name="city" class="input" maxlength="120" required
                   value="<?= $value('city') ?>" placeholder="Tallahassee" autocomplete="address-level2">
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="state">State</label>
                <input type="text" id="state" name="state" class="input" maxlength="2" required
                       value="<?= $value('state') !== '' ? $value('state') : 'FL' ?>"
                       autocomplete="address-level1" inputmode="text">
            </div>
            <div class="field">
                <label class="label" for="zip">ZIP</label>
                <input type="text" id="zip" name="zip" class="input" maxlength="10" required
                       value="<?= $value('zip') ?>" placeholder="32301"
                       autocomplete="postal-code" inputmode="numeric">
            </div>
        </div>

        <div class="field">
            <label class="label" for="instructions">Delivery notes <span class="optional">Optional</span></label>
            <textarea id="instructions" name="instructions" class="textarea" rows="2" maxlength="500"
                      placeholder="Gate code 4412, leave at the door"><?= $value('instructions') ?></textarea>
            <p class="help">The driver sees this when they arrive.</p>
        </div>

        <label class="check">
            <input type="checkbox" name="is_default" value="1"
                   <?= $editing !== null && (int) $editing['is_default'] === 1 ? 'checked' : '' ?>>
            <span class="check-text">Deliver here by default</span>
        </label>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg btn-block">
                <?= $editing === null ? 'Save address' : 'Save changes' ?>
            </button>
            <?php if ($editing !== null): ?>
            <a href="/app/addresses" class="btn btn-block">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php require __DIR__ . '/partials/bottom.php'; ?>
