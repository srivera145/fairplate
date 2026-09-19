<?php
/**
 * One statement's status, as a badge.
 *
 * Expects $statement. Shared by the billing page's recent list and the full
 * statements page, so the two never drift into describing the same row
 * differently.
 *
 * The wording is written for the kitchen rather than for the database. "Charge
 * didn't go through" is what a payment_failed row means to the person reading
 * it, and it is deliberately not alarming: nothing stops, and the next line on
 * the page tells them how to fix it.
 */

use Keel\App\Models\RestaurantMonthlyStatement;

$statementStatus = (string) $statement['status'];

[$statementTone, $statementLabel] = match ($statementStatus) {
    RestaurantMonthlyStatement::STATUS_FREE => ['good', 'Free month'],
    RestaurantMonthlyStatement::STATUS_PAID => ['good', 'Paid'],
    RestaurantMonthlyStatement::STATUS_INVOICED => ['', 'Invoiced'],
    RestaurantMonthlyStatement::STATUS_PAYMENT_FAILED => ['bad', "Charge didn't go through"],
    RestaurantMonthlyStatement::STATUS_NEEDS_REVIEW => ['warn', 'Rate being agreed'],
    RestaurantMonthlyStatement::STATUS_VOID => ['', 'Cancelled'],
    default => ['', 'Counting'],
};
?>
<span class="badge <?= $statementTone === '' ? '' : 'badge-' . $statementTone ?>">
    <?= htmlspecialchars($statementLabel, ENT_QUOTES, 'UTF-8') ?>
</span>
