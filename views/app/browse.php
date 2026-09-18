<?php
/**
 * Browse: what can bring me food, here, now.
 *
 * Open restaurants first, nearest first. Closed ones after, each carrying the
 * hour it opens, because a favourite that opens at five is more useful at four
 * than a shorter list.
 */

use EchoDial\Deck\Deck;

require __DIR__ . '/partials/top.php';

$open = $open ?? [];
$closed = $closed ?? [];
$search = (string) ($search ?? '');
$needsAddress = (bool) ($needsAddress ?? false);
$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

/** One card. Closed ones differ only in what the meta line says. */
$card = static function (array $entry) use ($escape): void {
    $restaurant = $entry['restaurant'];
    $miles = $entry['miles'];
    ?>
    <li class="card">
        <a class="restaurant-card <?= $entry['is_open'] ? '' : 'restaurant-closed' ?>"
           href="/app/r/<?= $escape((string) $restaurant['slug']) ?>">
            <?php if (($restaurant['logo'] ?? null) !== null): ?>
            <img class="restaurant-thumb" src="<?= $escape((string) $restaurant['logo']) ?>" alt="" loading="lazy">
            <?php else: ?>
            <span class="restaurant-thumb"><?= Deck::icon('receipt') ?></span>
            <?php endif; ?>

            <span class="stack stack-1">
                <span class="restaurant-name fw-semi"><?= $escape((string) $restaurant['name']) ?></span>
                <span class="restaurant-meta">
                    <?php if ($miles < PHP_FLOAT_MAX): ?>
                    <span><?= $escape(number_format($miles, 1)) ?> mi</span>
                    <?php endif; ?>
                    <?php if ($entry['has_special']): ?>
                    <span class="badge badge-brand"><?= Deck::icon('tag', 'icon icon-sm') ?> Special</span>
                    <?php endif; ?>
                    <?php if (!$entry['is_open']): ?>
                    <span class="badge badge-warn"><?= $escape((string) $entry['status_label']) ?></span>
                    <?php endif; ?>
                </span>
            </span>
        </a>
    </li>
    <?php
};
?>

<form method="GET" action="/app" class="field" role="search">
    <label class="sr-only" for="browse-search">Search restaurants and dishes</label>
    <div class="search">
        <?= Deck::icon('search') ?>
        <input type="search" id="browse-search" name="q" class="input"
               value="<?= $escape($search) ?>"
               placeholder="Search a restaurant or a dish" enterkeyhint="search">
    </div>
</form>

<?php if ($needsAddress): ?>
<section class="card">
    <div class="empty">
        <span class="empty-art"><?= Deck::icon('map-pin', 'icon icon-lg') ?></span>
        <h2 class="empty-title">Where are we delivering?</h2>
        <p class="text-muted">
            FairPlate needs an address before it can show you what is nearby and how far
            the food has to travel.
        </p>
        <a href="/app/addresses" class="btn btn-primary btn-lg">Add an address</a>
    </div>
</section>

<?php elseif (($zone ?? null) === null): ?>
<section class="card">
    <div class="empty">
        <span class="empty-art"><?= Deck::icon('map-pin', 'icon icon-lg') ?></span>
        <h2 class="empty-title">Not in our delivery area yet</h2>
        <p class="text-muted">
            Your default address sits outside every zone FairPlate covers today.
            Pick a different address, and we will tell you when we reach this one.
        </p>
        <a href="/app/addresses" class="btn btn-primary btn-lg">Choose another address</a>
    </div>
</section>

<?php elseif ($open === [] && $closed === []): ?>
<section class="card">
    <div class="empty">
        <span class="empty-art"><?= Deck::icon('search', 'icon icon-lg') ?></span>
        <h2 class="empty-title">Nothing matches that</h2>
        <p class="text-muted">
            <?= $search === ''
                ? 'No restaurants are signed up in your area yet.'
                : 'Try a shorter search, or a dish rather than a restaurant.' ?>
        </p>
        <?php if ($search !== ''): ?>
        <a href="/app" class="btn">Clear the search</a>
        <?php endif; ?>
    </div>
</section>

<?php else: ?>

<?php if ($open !== []): ?>
<section class="stack stack-3">
    <h2 class="h6 text-muted">Open now</h2>
    <ul class="stack stack-2">
        <?php foreach ($open as $entry) {
            $card($entry);
        } ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($closed !== []): ?>
<section class="stack stack-3">
    <h2 class="h6 text-muted">Closed right now</h2>
    <ul class="stack stack-2">
        <?php foreach ($closed as $entry) {
            $card($entry);
        } ?>
    </ul>
</section>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/partials/bottom.php'; ?>
