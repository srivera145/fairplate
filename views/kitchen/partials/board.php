<?php
/**
 * The three columns.
 *
 * Rendered twice: once inside the page, and once on its own by
 * OrdersController::feed() so the poll can swap it in. Nothing outside this
 * file goes into #orders-board, which is what keeps the two identical.
 *
 * Expects $columns from OrdersController::boardData().
 */

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<div class="kanban kitchen-board">
    <?php foreach (($columns ?? []) as $key => $column): ?>
    <section class="kanban-col" aria-label="<?= $escape((string) $column['label']) ?>">
        <h2 class="kanban-head">
            <?= $escape((string) $column['label']) ?>
            <span class="kanban-count"><?= count($column['orders']) ?></span>
        </h2>
        <div class="kanban-body">
            <?php foreach ($column['orders'] as $order): ?>
            <?php require __DIR__ . '/order-card.php'; ?>
            <?php endforeach; ?>
            <p class="kanban-empty">
                <?= $key === 'new' ? 'No new orders.' : 'Nothing here.' ?>
            </p>
        </div>
    </section>
    <?php endforeach; ?>
</div>
