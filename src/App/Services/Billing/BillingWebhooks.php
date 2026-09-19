<?php

namespace Keel\App\Services\Billing;

use Keel\App\Models\Restaurant;
use Keel\App\Models\RestaurantMonthlyStatement;
use Keel\App\Services\Payments\AdminAlert;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Activity;

/**
 * What Stripe says about a monthly invoice afterwards.
 *
 * The job that raised the invoice knows it was raised and nothing more. Whether
 * the card went through, and whether it went through three days later on the
 * second attempt, is only ever known here.
 *
 * The important rule in this file is the one it does not do. A restaurant whose
 * monthly fee failed to collect keeps taking orders. Its customers keep getting
 * dinner, its drivers keep getting paid, and its kitchen gets a banner and a
 * link to fix the card. Pausing a restaurant over an unpaid platform fee would
 * punish the people on both ends of an order for something neither of them did,
 * and every one of those orders is money the restaurant needs in order to pay
 * the fee. Nothing here touches restaurants.status or restaurants.paused, and
 * nothing should be added that does.
 *
 * Both handlers are written to run twice. The webhook log already stops the
 * same event id being worked twice, but Stripe describes the same fact in more
 * than one event — a paid invoice arrives as invoice.paid and also as
 * invoice.payment_succeeded — so each one asks what the row already says.
 */
class BillingWebhooks
{
    /**
     * The fee is collected.
     *
     * @param array<string, mixed> $invoice
     */
    public function invoicePaid(array $invoice): void
    {
        $statement = $this->statementFor($invoice);

        if ($statement === null) {
            return;
        }

        $statementId = (int) $statement['id'];

        if ((string) $statement['status'] === RestaurantMonthlyStatement::STATUS_PAID) {
            return;
        }

        RestaurantMonthlyStatement::update($statementId, array_filter([
            'status' => RestaurantMonthlyStatement::STATUS_PAID,
            // The PDF only exists once the invoice is finalized, and a statement
            // written before that has a null where the link goes.
            'invoice_pdf_url' => $this->url($invoice, 'invoice_pdf'),
            'hosted_invoice_url' => $this->url($invoice, 'hosted_invoice_url'),
        ], static fn (mixed $value): bool => $value !== null));

        Activity::log('billing.invoice_paid', 'Restaurant', (int) $statement['restaurant_id'], [
            'period' => (string) $statement['period'],
            'fee_cents' => (int) $statement['fee_cents'],
            'stripe_invoice_id' => (string) ($invoice['id'] ?? ''),
        ]);
    }

    /**
     * The fee did not collect.
     *
     * Marks the statement, alerts an admin, and stops. The kitchen picks the
     * status up on its next page load and shows the banner; nothing here
     * suspends, pauses or hides the restaurant.
     *
     * @param array<string, mixed> $invoice
     */
    public function invoicePaymentFailed(array $invoice): void
    {
        $statement = $this->statementFor($invoice);

        if ($statement === null) {
            return;
        }

        $statementId = (int) $statement['id'];

        if ((string) $statement['status'] === RestaurantMonthlyStatement::STATUS_PAID) {
            // A late retry on an invoice that has since been paid by hand.
            return;
        }

        if ((string) $statement['status'] !== RestaurantMonthlyStatement::STATUS_PAYMENT_FAILED) {
            RestaurantMonthlyStatement::update($statementId, array_filter([
                'status' => RestaurantMonthlyStatement::STATUS_PAYMENT_FAILED,
                'hosted_invoice_url' => $this->url($invoice, 'hosted_invoice_url'),
                'invoice_pdf_url' => $this->url($invoice, 'invoice_pdf'),
            ], static fn (mixed $value): bool => $value !== null));
        }

        $restaurantId = (int) $statement['restaurant_id'];
        $restaurant = Restaurant::find($restaurantId);

        AdminAlert::raise('billing.payment_failed', sprintf(
            '%s could not be charged %s for %s. The restaurant is still taking orders.',
            (string) ($restaurant['name'] ?? 'Restaurant ' . $restaurantId),
            Money::usd((int) $statement['fee_cents']),
            TierBillingService::periodName((string) $statement['period'])
        ), [
            'restaurant_id' => $restaurantId,
            'period' => (string) $statement['period'],
            'statement_id' => $statementId,
            'stripe_invoice_id' => (string) ($invoice['id'] ?? ''),
        ]);
    }

    /**
     * The statement an invoice belongs to.
     *
     * Found by the invoice id first, which is what the job wrote down. The
     * metadata is the fallback for the window between Stripe creating the
     * invoice and the job saving its id — a fast webhook can beat that write.
     *
     * @param array<string, mixed> $invoice
     */
    private function statementFor(array $invoice): ?array
    {
        $invoiceId = trim((string) ($invoice['id'] ?? ''));
        $statement = $invoiceId === '' ? null : RestaurantMonthlyStatement::findByInvoice($invoiceId);

        if ($statement !== null) {
            return $statement;
        }

        $metadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];
        $restaurantId = (int) ($metadata['restaurant_id'] ?? 0);
        $period = trim((string) ($metadata['period'] ?? ''));

        if ($restaurantId <= 0 || $period === '') {
            // Not one of ours. Keel's own subscription invoices come through the
            // same endpoint and are none of this class's business.
            return null;
        }

        $statement = RestaurantMonthlyStatement::forPeriod($restaurantId, $period);

        if ($statement !== null && $invoiceId !== '' && trim((string) $statement['stripe_invoice_id']) === '') {
            RestaurantMonthlyStatement::update((int) $statement['id'], ['stripe_invoice_id' => $invoiceId]);

            return RestaurantMonthlyStatement::find((int) $statement['id']);
        }

        return $statement;
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private function url(array $invoice, string $key): ?string
    {
        $value = trim((string) ($invoice[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
