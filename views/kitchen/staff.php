<?php
/**
 * Who can open this kitchen.
 *
 * There is no invitation to accept. Sign-in is a phone number and a texted
 * code, so adding someone here is the whole of it — they open the app, type
 * their number, and this kitchen is what they see.
 */

use EchoDial\Deck\Deck;
use Keel\Core\Csrf;

require __DIR__ . '/partials/top.php';

$staff = $staff ?? [];
$isOwner = $isOwner ?? false;
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>

<?php if ($isOwner): ?>
<section class="card">
    <div class="card-header"><h2 class="card-title">Add someone</h2></div>
    <form method="POST" action="/kitchen/staff" class="card-body field-row">
        <?= Csrf::field() ?>
        <div class="field">
            <label class="label" for="staff-phone">Mobile number</label>
            <input type="tel" id="staff-phone" name="phone" class="input" inputmode="tel"
                   placeholder="(850) 555-0123" required>
            <p class="help">They sign in with this number. No password, no email.</p>
        </div>
        <div class="field">
            <label class="label" for="staff-name">Name <span class="optional">Optional</span></label>
            <input type="text" id="staff-name" name="name" class="input">
        </div>
        <div class="field">
            <label class="label" aria-hidden="true">&nbsp;</label>
            <button type="submit" class="btn btn-primary btn-lg"><?= Deck::icon('plus') ?> Add to kitchen</button>
        </div>
    </form>
</section>
<?php else: ?>
<div class="alert alert-info">
    <p class="alert-body">Only the owner of this restaurant can add or remove staff.</p>
</div>
<?php endif; ?>

<section class="card">
    <div class="card-header">
        <h2 class="card-title">Your team</h2>
        <span class="badge push"><?= count($staff) ?></span>
    </div>
    <ul class="list">
        <?php foreach ($staff as $member): ?>
        <li class="list-row">
            <span class="list-main">
                <span class="list-title">
                    <?= $escape(trim((string) ($member['name'] ?? '')) !== '' ? (string) $member['name'] : 'No name yet') ?>
                </span>
                <span class="list-sub"><?= $escape((string) ($member['phone'] ?? '')) ?></span>
            </span>
            <span class="list-trail cluster gap-2">
                <?php if ((int) $member['is_owner'] === 1): ?>
                <span class="badge badge-brand">Owner</span>
                <?php elseif ($isOwner): ?>
                <form method="POST" action="/kitchen/staff/<?= (int) $member['id'] ?>/delete"
                      onsubmit="return confirm('Remove this person from your kitchen?');">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn"><?= Deck::icon('trash') ?> Remove</button>
                </form>
                <?php endif; ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
</section>

<?php require __DIR__ . '/partials/bottom.php'; ?>
