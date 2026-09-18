<?php
/**
 * The restaurant profile and the Stripe payouts panel.
 *
 * Two forms, deliberately: the profile is one save, and each image is its own
 * upload, so a slow photo on a kitchen's connection never costs someone the
 * address they just typed.
 */

use EchoDial\Deck\Deck;
use Keel\Core\Csrf;

require __DIR__ . '/partials/top.php';

$form = $form ?? [];
$errors = $errors ?? [];
$connect = $connect ?? null;
$field = static fn (string $key): string => htmlspecialchars((string) ($form[$key] ?? ''), ENT_QUOTES, 'UTF-8');
$error = static fn (string $key): string => htmlspecialchars((string) ($errors[$key] ?? ''), ENT_QUOTES, 'UTF-8');
?>

<section class="card">
    <div class="card-header">
        <h2 class="card-title"><?= $restaurant === null ? 'Tell us about your restaurant' : 'Restaurant profile' ?></h2>
    </div>
    <form method="POST" action="/kitchen/onboarding" class="card-body stack stack-4">
        <?= Csrf::field() ?>

        <div class="field">
            <label class="label" for="name">Restaurant name <span class="required">Required</span></label>
            <input type="text" id="name" name="name" class="input <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                   value="<?= $field('name') ?>" required>
            <?php if (isset($errors['name'])): ?><p class="error"><?= $error('name') ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="phone">Phone <span class="optional">Optional</span></label>
            <input type="tel" id="phone" name="phone" class="input" inputmode="tel" value="<?= $field('phone') ?>">
            <p class="help">Drivers call this number if they cannot find the door.</p>
        </div>

        <div class="field">
            <label class="label" for="line1">Street address <span class="required">Required</span></label>
            <input type="text" id="line1" name="line1" class="input <?= isset($errors['line1']) ? 'is-invalid' : '' ?>"
                   autocomplete="street-address" value="<?= $field('line1') ?>" required>
            <?php if (isset($errors['line1'])): ?><p class="error"><?= $error('line1') ?></p><?php endif; ?>
            <p class="help">We look this up on a map. It has to be inside a FairPlate delivery zone.</p>
        </div>

        <div class="field">
            <label class="label" for="line2">Suite or unit <span class="optional">Optional</span></label>
            <input type="text" id="line2" name="line2" class="input" value="<?= $field('line2') ?>">
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="city">City</label>
                <input type="text" id="city" name="city" class="input <?= isset($errors['city']) ? 'is-invalid' : '' ?>"
                       value="<?= $field('city') ?>" required>
                <?php if (isset($errors['city'])): ?><p class="error"><?= $error('city') ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label class="label" for="state">State</label>
                <input type="text" id="state" name="state" class="input <?= isset($errors['state']) ? 'is-invalid' : '' ?>"
                       maxlength="2" value="<?= $field('state') ?>" required>
                <?php if (isset($errors['state'])): ?><p class="error"><?= $error('state') ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="label" for="zip">ZIP</label>
                <input type="text" id="zip" name="zip" class="input <?= isset($errors['zip']) ? 'is-invalid' : '' ?>"
                       inputmode="numeric" value="<?= $field('zip') ?>" required>
                <?php if (isset($errors['zip'])): ?><p class="error"><?= $error('zip') ?></p><?php endif; ?>
            </div>
            <div class="field">
                <label class="label" for="tax_rate">Sales tax</label>
                <div class="input-group">
                    <input type="text" id="tax_rate" name="tax_rate"
                           class="input <?= isset($errors['tax_rate']) ? 'is-invalid' : '' ?>"
                           inputmode="decimal" placeholder="7.5" value="<?= $field('tax_rate') ?>" required>
                    <span class="addon">%</span>
                </div>
                <?php if (isset($errors['tax_rate'])): ?><p class="error"><?= $error('tax_rate') ?></p><?php endif; ?>
                <p class="help">The rate you charge in store. FairPlate never marks your prices up.</p>
            </div>
        </div>

        <?php if ($restaurant === null): ?>
        <fieldset class="fieldset">
            <legend>Opening hours to start with</legend>
            <p class="help">Same every day for now. You can set each day, split shifts and holidays on the Hours screen.</p>
            <div class="field-row">
                <div class="field">
                    <label class="label" for="open">Opens</label>
                    <input type="time" id="open" name="open" class="input <?= isset($errors['open']) ? 'is-invalid' : '' ?>"
                           value="<?= $field('open') ?>" required>
                    <?php if (isset($errors['open'])): ?><p class="error"><?= $error('open') ?></p><?php endif; ?>
                </div>
                <div class="field">
                    <label class="label" for="close">Closes</label>
                    <input type="time" id="close" name="close" class="input <?= isset($errors['close']) ? 'is-invalid' : '' ?>"
                           value="<?= $field('close') ?>" required>
                    <?php if (isset($errors['close'])): ?><p class="error"><?= $error('close') ?></p><?php endif; ?>
                </div>
            </div>
        </fieldset>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <?= $restaurant === null ? 'Create my restaurant' : 'Save profile' ?>
            </button>
        </div>
    </form>
</section>

<?php if ($restaurant !== null): ?>
<section class="card">
    <div class="card-header">
        <h2 class="card-title">Logo and cover photo</h2>
    </div>
    <div class="card-body stack stack-4">
        <?php if (!($photosAvailable ?? false)): ?>
        <div class="alert alert-warn">
            <p class="alert-body">Image uploads need PHP's GD extension with WebP support. Ask your host to enable it.</p>
        </div>
        <?php endif; ?>

        <div class="grid grid-2">
            <?php foreach (['logo' => 'Logo', 'cover' => 'Cover photo'] as $kind => $label): ?>
            <form method="POST" action="/kitchen/onboarding/photo/<?= $kind ?>" enctype="multipart/form-data"
                  class="stack stack-3">
                <?= Csrf::field() ?>
                <h3 class="h6"><?= $label ?></h3>
                <?php if (($restaurant[$kind] ?? null) !== null): ?>
                <img src="<?= htmlspecialchars((string) $restaurant[$kind], ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= $label ?>" class="r-md" style="max-inline-size: 12rem">
                <?php endif; ?>
                <label class="file">
                    <input type="file" name="photo" accept="image/*" <?= ($photosAvailable ?? false) ? '' : 'disabled' ?>>
                    <span><?= Deck::icon('upload') ?> Choose an image</span>
                </label>
                <button type="submit" class="btn" <?= ($photosAvailable ?? false) ? '' : 'disabled' ?>>Upload</button>
            </form>
            <?php endforeach; ?>
        </div>
        <p class="text-sm text-muted">
            Images are resized to <?= \Keel\App\Services\ImageService::MAX_EDGE ?>px and saved as WebP, so they load fast on a phone.
        </p>
    </div>
</section>

<section class="card">
    <div class="card-header">
        <h2 class="card-title">Getting paid</h2>
        <?php if ($connect !== null && $connect['payouts_enabled']): ?>
        <span class="badge badge-good push">Payouts on</span>
        <?php elseif ($connect !== null && $connect['connected']): ?>
        <span class="badge badge-warn push">In review</span>
        <?php else: ?>
        <span class="badge push">Not started</span>
        <?php endif; ?>
    </div>
    <div class="card-body stack stack-4">
        <p>
            FairPlate takes no commission and deducts nothing from your orders. Your food and tax go
            straight to your own Stripe account; you pay one flat monthly fee based on last month's
            completed orders, and months under 41 orders are free.
        </p>

        <?php if ($connect !== null && $connect['requirements'] !== []): ?>
        <div class="alert alert-warn">
            <p class="alert-title">Stripe still needs:</p>
            <ul class="alert-body">
                <?php foreach ($connect['requirements'] as $requirement): ?>
                <li><?= htmlspecialchars(str_replace(['_', '.'], [' ', ' — '], $requirement), ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" action="/kitchen/connect/start">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-primary btn-lg">
                <?= Deck::icon('credit-card') ?>
                <?= ($connect !== null && $connect['connected']) ? 'Continue with Stripe' : 'Set up payouts with Stripe' ?>
            </button>
        </form>

        <p class="text-sm text-muted">
            Finishing with Stripe does not open your restaurant on its own — FairPlate reviews and
            approves every new kitchen. Your status is
            <strong><?= htmlspecialchars((string) $restaurant['status'], ENT_QUOTES, 'UTF-8') ?></strong>.
        </p>
    </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
