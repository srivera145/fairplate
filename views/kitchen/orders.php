<?php
/**
 * The live orders board.
 *
 * Everything below #orders-board's opening tag is replaced wholesale by the
 * poll, so nothing that holds state — the sound gate, the 86 list — lives
 * inside it.
 */

use EchoDial\Deck\Deck;
use Keel\App\Models\Restaurant;
use Keel\Core\Csrf;

$kitchenScripts = ['/js/kitchen-orders.js'];
require __DIR__ . '/partials/top.php';

$boardRestaurant = $boardRestaurant ?? null;
$menuItems = $menuItems ?? [];
?>

<?php if ($boardRestaurant === null): ?>
<section class="card">
    <div class="empty">
        <span class="empty-art"><?= Deck::icon('home', 'icon icon-lg') ?></span>
        <h2 class="empty-title">Let us set your restaurant up</h2>
        <p>Your account is not attached to a restaurant yet. The profile takes about two minutes.</p>
        <a href="/kitchen/onboarding" class="btn btn-primary btn-lg">Start your profile</a>
    </div>
</section>
<?php else: ?>

<div id="sound-gate" class="alert alert-info sound-gate" hidden>
    <div class="alert-body bar wrap gap-3">
        <span>
            <strong>Sound is off.</strong>
            Your browser will not play the new-order chime until you tap once.
        </span>
        <button type="button" id="sound-enable" class="btn btn-primary btn-lg push">
            <?= Deck::icon('bell') ?> Tap to enable sound
        </button>
    </div>
</div>

<div id="chime-stop-wrap" hidden>
    <button type="button" id="chime-stop" class="btn btn-lg btn-block">
        <?= Deck::icon('bell') ?> Stop the chime — I have seen the new orders
    </button>
</div>

<div id="board-offline" class="alert alert-warn" role="status" hidden>
    <p class="alert-body">
        <strong>Not updating.</strong>
        The board cannot reach FairPlate. It will catch up on its own.
    </p>
</div>

<div id="orders-board"
     data-feed="/kitchen/orders/feed"
     data-chime="/sounds/new-order.mp3"
     data-signature="<?= htmlspecialchars((string) $signature, ENT_QUOTES, 'UTF-8') ?>">
    <?php require __DIR__ . '/partials/board.php'; ?>
</div>

<section class="card">
    <details>
        <summary class="card-header cursor-pointer">
            <h2 class="card-title">86 an item</h2>
            <span class="badge push">
                <?= count(array_filter($menuItems, static fn (array $i): bool => (int) $i['in_stock'] === 0)) ?> off
            </span>
        </summary>
        <div class="card-body stack stack-2">
            <p class="text-sm text-muted">
                Turning an item off hides it from customers straight away. It stays on your menu.
            </p>
            <?php if ($menuItems === []): ?>
            <p class="text-sm">Nothing on the menu yet. <a href="/kitchen/menu">Add your first item.</a></p>
            <?php endif; ?>
            <ul class="list">
                <?php foreach ($menuItems as $item): ?>
                <li class="list-row">
                    <span class="list-main">
                        <span class="list-title"><?= htmlspecialchars((string) $item['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ((int) $item['in_stock'] === 0): ?>
                        <span class="list-sub text-bad">86ed — hidden from customers</span>
                        <?php endif; ?>
                    </span>
                    <form method="POST" action="/kitchen/menu/items/<?= (int) $item['id'] ?>/stock" class="list-trail">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="back" value="/kitchen">
                        <button type="submit" class="btn <?= (int) $item['in_stock'] === 1 ? 'btn-outline' : 'btn-primary' ?>">
                            <?= (int) $item['in_stock'] === 1 ? '86 it' : 'Back on' ?>
                        </button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </details>
</section>

<?php if (!Restaurant::isPaused($boardRestaurant)): ?>
<section class="card">
    <div class="card-body stack stack-3">
        <h2 class="card-title">Pause orders</h2>
        <p class="text-sm text-muted">Stops new orders reaching this board. Anything already accepted still stands.</p>
        <form method="POST" action="/kitchen/pause" class="order-choices">
            <?= Csrf::field() ?>
            <input type="hidden" name="back" value="/kitchen">
            <?php foreach (Restaurant::PAUSE_MINUTES as $minutes): ?>
            <button type="submit" name="minutes" value="<?= $minutes ?>" class="btn btn-lg btn-outline">
                <?= $minutes ?> min
            </button>
            <?php endforeach; ?>
            <button type="submit" name="minutes" value="until_resumed" class="btn btn-lg btn-danger">
                Until I resume
            </button>
        </form>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
