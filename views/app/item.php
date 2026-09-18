<?php
/**
 * One item, as its own page.
 *
 * This is where the link under every menu row goes when the sheet cannot open
 * — no JavaScript, a shared link, a back button pressed after an error. The
 * form is the same partial the sheet shows, so the two never diverge.
 */

$deckExtras = true;
require __DIR__ . '/partials/top.php';

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>

<p>
    <a href="/app/r/<?= $escape((string) $restaurant['slug']) ?>" class="btn btn-ghost btn-sm">
        &larr; <?= $escape((string) $restaurant['name']) ?>
    </a>
</p>

<?php if (($item['photo'] ?? null) !== null): ?>
<img class="ratio-4x3 r-md" src="<?= $escape((string) $item['photo']) ?>" alt="">
<?php endif; ?>

<section class="card">
    <div class="card-body">
        <?php require __DIR__ . '/partials/item-form.php'; ?>
    </div>
</section>

<?php require __DIR__ . '/partials/bottom.php'; ?>
