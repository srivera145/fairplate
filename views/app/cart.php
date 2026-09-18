<?php
/**
 * The cart.
 *
 * Every price on this page was worked out by PricingService from the menu a
 * moment ago, which is why a line can arrive carrying a problem: the item was
 * 86'd, hidden or re-optioned since it went in. Saying so here is the point —
 * the alternative is a total that quietly changes at checkout.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Address;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Csrf;

$deckExtras = true;
require __DIR__ . '/partials/top.php';

$lines = $summary['lines'] ?? [];
$restaurant = $summary['restaurant'] ?? null;
$addresses = $addresses ?? [];
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>

<?php if ($lines === [] || $restaurant === null): ?>
<section class="card">
    <div class="empty">
        <span class="empty-art"><?= Deck::icon('receipt', 'icon icon-lg') ?></span>
        <h2 class="empty-title">Your cart is empty</h2>
        <p class="text-muted">Pick a restaurant and we will keep your cart here, on any device you sign in from.</p>
        <a href="/app" class="btn btn-primary btn-lg">Find something to eat</a>
    </div>
</section>

<?php else: ?>

<section class="stack stack-2">
    <div class="bar gap-2 wrap">
        <h1 class="h4"><?= $escape((string) $restaurant['name']) ?></h1>
        <?php if (!$isOpen): ?>
        <span class="badge badge-warn push"><?= $escape((string) $statusLabel) ?></span>
        <?php endif; ?>
    </div>
    <p class="text-sm">
        <a href="/app/r/<?= $escape((string) $restaurant['slug']) ?>" class="link-quiet">Add more from this menu</a>
    </p>
</section>

<?php if ($summary['has_problems']): ?>
<div class="alert alert-warn" role="alert">
    <?= Deck::icon('alert-triangle') ?>
    <p class="alert-body">Something in your cart has changed since you added it. Fix the lines below to carry on.</p>
</div>
<?php endif; ?>

<section class="card">
    <div class="card-body">
        <ul>
            <?php foreach ($lines as $line): ?>
            <li class="cart-line">
                <div class="stack stack-1">
                    <span class="fw-semi"><?= $escape((string) $line['name']) ?></span>

                    <?php if ($line['options'] !== []): ?>
                    <span class="text-sm text-muted">
                        <?php
                        $labels = array_map(
                            static fn (array $option): string => (string) $option['name'],
                            $line['options']
                        );
                        ?>
                        <?= $escape(implode(', ', $labels)) ?>
                    </span>
                    <?php endif; ?>

                    <?php if (trim((string) $line['notes']) !== ''): ?>
                    <span class="text-sm text-muted">“<?= $escape((string) $line['notes']) ?>”</span>
                    <?php endif; ?>

                    <?php if ($line['problem'] !== null): ?>
                    <span class="badge badge-bad"><?= $escape((string) $line['problem']) ?></span>
                    <?php endif; ?>

                    <?php if ((int) $line['discount_cents'] > 0): ?>
                    <span class="badge badge-brand">
                        Special saves <?= $escape(Money::usd((int) $line['discount_cents'])) ?>
                    </span>
                    <?php endif; ?>
                </div>

                <div class="stack stack-2 text-end">
                    <span class="nums fw-semi"><?= $escape(Money::usd((int) $line['line_cents'])) ?></span>

                    <form method="POST" action="/app/cart/items/<?= (int) $line['id'] ?>" class="stack stack-2">
                        <?= Csrf::field() ?>
                        <label class="sr-only" for="qty-<?= (int) $line['id'] ?>">Quantity</label>
                        <div class="number number-sm">
                            <button type="button" data-step="-1" aria-label="Fewer">−</button>
                            <input type="number" id="qty-<?= (int) $line['id'] ?>" name="quantity"
                                   value="<?= (int) $line['quantity'] ?>" min="0" max="25" step="1" inputmode="numeric">
                            <button type="button" data-step="1" aria-label="More">+</button>
                        </div>
                        <button type="submit" class="btn btn-sm">Update</button>
                    </form>

                    <form method="POST" action="/app/cart/items/<?= (int) $line['id'] ?>/delete">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-ghost text-bad">Remove</button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="card">
    <div class="card-body stack stack-3">
        <div class="bar">
            <span class="text-muted">Food subtotal</span>
            <span class="nums fw-semi push"><?= $escape(Money::usd((int) $summary['subtotal_cents'])) ?></span>
        </div>
        <p class="text-sm text-muted">
            Delivery, tax, tip and fees are worked out at checkout, where you will see every line.
        </p>
    </div>
</section>

<?php if ($addresses !== []): ?>
<section class="card">
    <form method="POST" action="/app/cart/address" class="card-body stack stack-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="back" value="/app/cart">
        <div class="field">
            <label class="label" for="cart-address">Deliver to</label>
            <select id="cart-address" name="address_id" class="select">
                <?php foreach ($addresses as $address): ?>
                <option value="<?= (int) $address['id'] ?>"
                    <?= (int) ($summary['cart']['address_id'] ?? 0) === (int) $address['id']
                        || ((int) ($summary['cart']['address_id'] ?? 0) === 0 && (int) $address['is_default'] === 1)
                        ? 'selected' : '' ?>>
                    <?= $escape((string) $address['label']) ?> — <?= $escape(Address::oneLine($address)) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn">Use this address</button>
    </form>
</section>
<?php endif; ?>

<div class="form-actions form-actions-sticky">
    <a href="/app/checkout" class="btn btn-primary btn-lg btn-block">Go to checkout</a>
    <form method="POST" action="/app/cart/clear">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-ghost btn-block text-bad">Empty the cart</button>
    </form>
</div>

<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
