<?php
/**
 * "Your cart has food from somewhere else."
 *
 * A cart holds one restaurant at a time, so this is the moment somebody has to
 * choose. It is a question, not an error: both restaurants are named, the
 * button says exactly what it will throw away, and nothing has been thrown away
 * yet. Backing out leaves the first cart untouched.
 */

use EchoDial\Deck\Deck;
use Keel\Core\Csrf;

require __DIR__ . '/partials/top.php';

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$optionIds = $optionIds ?? [];
?>

<section class="card">
    <div class="card-body stack stack-4">
        <div class="empty">
            <span class="empty-art"><?= Deck::icon('alert-circle', 'icon icon-lg') ?></span>
            <h1 class="empty-title">Start a new cart?</h1>
            <p class="text-muted">
                Your cart has food from <strong><?= $escape((string) $current['name']) ?></strong>.
                FairPlate delivers one restaurant per order, so adding from
                <strong><?= $escape((string) $wanted['name']) ?></strong> means emptying it first.
            </p>
        </div>

        <form method="POST" action="/app/cart/items" class="stack stack-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="menu_item_id" value="<?= (int) $menuItemId ?>">
            <input type="hidden" name="quantity" value="<?= (int) $quantity ?>">
            <input type="hidden" name="notes" value="<?= $escape((string) $notes) ?>">
            <?php foreach ($optionIds as $index => $optionId): ?>
            <input type="hidden" name="options[<?= (int) $index ?>]" value="<?= (int) $optionId ?>">
            <?php endforeach; ?>
            <input type="hidden" name="replace_cart" value="1">

            <button type="submit" class="btn btn-danger btn-lg btn-block">
                Empty it and start at <?= $escape((string) $wanted['name']) ?>
            </button>
        </form>

        <a href="/app/cart" class="btn btn-block">Keep my <?= $escape((string) $current['name']) ?> cart</a>
    </div>
</section>

<?php require __DIR__ . '/partials/bottom.php'; ?>
