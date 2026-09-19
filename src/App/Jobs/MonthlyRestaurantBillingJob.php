<?php

namespace Keel\App\Jobs;

use Keel\App\Models\Restaurant;
use Keel\App\Models\RestaurantMonthlyStatement;
use Keel\App\Models\RestaurantStaff;
use Keel\App\Services\Billing\BillingCustomer;
use Keel\App\Services\Billing\TierBillingService;
use Keel\App\Services\Payments\AdminAlert;
use Keel\App\Services\Pricing\Money;
use Keel\App\Services\StripeClientFactory;
use Keel\Core\Activity;
use Keel\Core\Sms;
use Stripe\StripeClient;

/**
 * The first of the month: count last month, write it down, and bill it.
 *
 * Runs at 06:00 America/New_York on the 1st. The hour is not arbitrary — the
 * month it is billing ends at midnight Eastern, and six hours of margin means
 * an order delivered at 11:58pm on the 31st has been captured, priced and
 * written to its final breakdown long before anything here counts it.
 *
 * Scheduling is the deployment's job, the same way the queue worker is.
 * database/bill-restaurants.php is the entry point and runs this inline rather
 * than queueing it, so a worker that is down cannot turn the month's invoicing
 * into something that silently never happened.
 *
 * Three endings, and the difference between them matters:
 *
 *   - a fee above zero becomes a Stripe invoice against the restaurant's own
 *     customer, charged automatically against the method it put on file;
 *   - a fee of exactly zero is the months under the first tier, which the spec
 *     says are free. Those get a statement and a text message and no invoice,
 *     because an invoice for $0.00 is a bill, and a restaurant that reads it as
 *     one has been told the wrong thing;
 *   - the custom tier with no figure agreed gets a statement marked for review
 *     and an alert to an admin. It does not get an invoice and it does not get
 *     a guess.
 *
 * Running it twice for the same month must produce one statement and one
 * invoice, and it is guarded twice over. The unique index on
 * (restaurant_id, period) decides who writes the statement; an idempotency key
 * built from the same pair decides what Stripe does with a second create. The
 * index alone would be enough on one worker, and is not enough on two.
 */
class MonthlyRestaurantBillingJob implements Job
{
    /** The payment term the invoice states. */
    public const NET_DAYS = 7;

    /**
     * @param array{period?: string, restaurant_id?: int} $data
     */
    public function handle(array $data): void
    {
        $period = trim((string) ($data['period'] ?? '')) ?: TierBillingService::previousPeriod();
        $billing = TierBillingService::fromTables();

        foreach ($this->restaurants($data) as $restaurant) {
            try {
                $this->billOne($billing, $restaurant, $period);
            } catch (\Throwable $exception) {
                // One restaurant's Stripe error must not stop the other forty
                // being billed. It is logged, alerted and stepped over, and the
                // statement it left behind is the one a re-run picks up.
                AdminAlert::raise('billing.statement_failed', sprintf(
                    'Billing %s for %s failed: %s',
                    (string) $restaurant['name'],
                    $period,
                    $exception->getMessage()
                ), [
                    'restaurant_id' => (int) $restaurant['id'],
                    'period' => $period,
                ]);
            }
        }
    }

    /**
     * One restaurant, one month.
     *
     * @return array the statement row as it now stands
     */
    public function billOne(TierBillingService $billing, array $restaurant, string $period): array
    {
        $restaurantId = (int) $restaurant['id'];
        $figures = $billing->statementFor($restaurant, $period);

        [$statement, $created] = RestaurantMonthlyStatement::claim($restaurantId, $period, [
            'orders' => $figures['orders'],
            'sales_subtotal_cents' => $figures['sales_subtotal_cents'],
            'tier_id' => $figures['tier_id'],
            'fee_cents' => (int) $figures['fee_cents'],
            'discount_cents' => $figures['discount_cents'],
            'commission_equiv_cents' => $figures['commission_equiv_cents'],
            'savings_cents' => $figures['savings_cents'],
            'status' => RestaurantMonthlyStatement::STATUS_DRAFT,
        ]);

        if (!$created && in_array((string) $statement['status'], RestaurantMonthlyStatement::SETTLED_STATUSES, true)) {
            // Already dealt with. This is the second run of the same month, and
            // the whole point of the index is that it ends here.
            return $statement;
        }

        if ($created) {
            Activity::log('billing.statement_created', 'Restaurant', $restaurantId, [
                'period' => $period,
                'orders' => $figures['orders'],
                'fee_cents' => $figures['fee_cents'],
            ]);
        }

        if ($figures['fee_cents'] === null) {
            return $this->needsReview($statement, $restaurant, $figures);
        }

        if ($figures['fee_cents'] <= 0) {
            return $this->free($statement, $restaurant, $figures);
        }

        return $this->invoice($statement, $restaurant, $figures);
    }

    // -----------------------------------------------------------------
    // The three endings
    // -----------------------------------------------------------------

    /**
     * The custom tier, with nothing agreed. A person has to decide.
     */
    private function needsReview(array $statement, array $restaurant, array $figures): array
    {
        $statementId = (int) $statement['id'];

        RestaurantMonthlyStatement::update($statementId, [
            'status' => RestaurantMonthlyStatement::STATUS_NEEDS_REVIEW,
        ]);

        AdminAlert::raise('billing.custom_fee_unset', sprintf(
            '%s did %d orders in %s, which is the custom tier, and no custom fee is set. Nothing was invoiced.',
            (string) $restaurant['name'],
            $figures['orders'],
            TierBillingService::periodName($figures['period'])
        ), [
            'restaurant_id' => (int) $restaurant['id'],
            'period' => $figures['period'],
            'orders' => $figures['orders'],
            'statement_id' => $statementId,
        ]);

        return (array) RestaurantMonthlyStatement::find($statementId);
    }

    /**
     * Under the first tier. Nothing to collect, and something worth saying.
     */
    private function free(array $statement, array $restaurant, array $figures): array
    {
        $statementId = (int) $statement['id'];

        RestaurantMonthlyStatement::update($statementId, [
            'status' => RestaurantMonthlyStatement::STATUS_FREE,
        ]);

        $this->text($restaurant, sprintf(
            'FairPlate: %s was free last month. %d %s delivered in %s, which is under the first tier, so there is no fee. You kept %s in food and tax.',
            (string) $restaurant['name'],
            $figures['orders'],
            $figures['orders'] === 1 ? 'order' : 'orders',
            TierBillingService::periodName($figures['period']),
            Money::usd($figures['sales_subtotal_cents'])
        ));

        Activity::log('billing.statement_free', 'Restaurant', (int) $restaurant['id'], [
            'period' => $figures['period'],
            'orders' => $figures['orders'],
        ]);

        return (array) RestaurantMonthlyStatement::find($statementId);
    }

    /**
     * A fee to collect.
     *
     * The invoice is created empty and the line is added to it by id, rather
     * than the other way round. An invoice item created loose belongs to the
     * customer's next invoice, whichever that turns out to be, and a second run
     * that failed partway would sweep the stray line into a later month.
     *
     * collection_method is charge_automatically, which is what makes the card
     * or ACH mandate on file worth collecting. Stripe only honours
     * days_until_due on invoices a customer pays by hand, so the net-7 term is
     * stated on the invoice and stored on the statement rather than enforced by
     * Stripe — the charge is attempted at once, and the term is what FairPlate
     * gives them before anyone follows it up.
     */
    private function invoice(array $statement, array $restaurant, array $figures): array
    {
        $statementId = (int) $statement['id'];
        $restaurantId = (int) $restaurant['id'];
        $period = (string) $figures['period'];
        $feeCents = (int) $figures['fee_cents'];

        $customers = new BillingCustomer();
        $customerId = $customers->customerIdFor($restaurant);

        $key = $this->idempotencyKey($restaurantId, $period);
        $monthName = TierBillingService::periodName($period);
        $dueOn = $this->dueDate($period);
        $stripe = $this->stripe();

        $invoice = $stripe->invoices->create([
            'customer' => $customerId,
            'collection_method' => 'charge_automatically',
            'auto_advance' => true,
            'description' => 'FairPlate monthly fee for ' . $monthName
                . '. No commission is taken from your orders.',
            'footer' => 'Net ' . self::NET_DAYS . ' — due ' . $dueOn . '.',
            'metadata' => [
                'restaurant_id' => (string) $restaurantId,
                'period' => $period,
                'statement_id' => (string) $statementId,
                'orders' => (string) $figures['orders'],
            ],
        ], ['idempotency_key' => $key]);

        $stripe->invoiceItems->create([
            'customer' => $customerId,
            'invoice' => (string) $invoice->id,
            'amount' => $feeCents,
            'currency' => 'usd',
            'description' => sprintf('FairPlate — %s — %s', (string) $figures['tier_name'], $monthName),
            'metadata' => ['restaurant_id' => (string) $restaurantId, 'period' => $period],
        ], ['idempotency_key' => $key . '-line']);

        $finalized = $stripe->invoices->finalizeInvoice((string) $invoice->id, ['auto_advance' => true]);

        RestaurantMonthlyStatement::update($statementId, [
            'stripe_invoice_id' => (string) $finalized->id,
            'due_on' => $dueOn,
            'invoice_pdf_url' => $this->urlOf($finalized, 'invoice_pdf'),
            'hosted_invoice_url' => $this->urlOf($finalized, 'hosted_invoice_url'),
            'status' => RestaurantMonthlyStatement::STATUS_INVOICED,
        ]);

        Activity::log('billing.invoiced', 'Restaurant', $restaurantId, [
            'period' => $period,
            'orders' => $figures['orders'],
            'fee_cents' => $feeCents,
            'stripe_invoice_id' => (string) $finalized->id,
        ]);

        return (array) RestaurantMonthlyStatement::find($statementId);
    }

    // -----------------------------------------------------------------
    // Plumbing
    // -----------------------------------------------------------------

    /**
     * Who is being billed: every active restaurant, or the one asked for.
     *
     * Pending restaurants have not been approved to take orders and suspended
     * ones have been stopped, so neither has a month to bill. A suspended
     * restaurant that traded earlier in the month keeps whatever statement it
     * already has; nothing here writes a new one for it.
     */
    private function restaurants(array $data): array
    {
        $restaurantId = (int) ($data['restaurant_id'] ?? 0);

        if ($restaurantId > 0) {
            $restaurant = Restaurant::find($restaurantId);

            return $restaurant === null ? [] : [$restaurant];
        }

        return Restaurant::withStatus(Restaurant::STATUS_ACTIVE);
    }

    /**
     * One key per restaurant per month, for every Stripe write this makes.
     *
     * The statement row is the first guard and this is the second. They defend
     * different things: the row stops a second statement, the key stops a
     * second invoice in the window where the first run has called Stripe and
     * not yet written the id back.
     */
    private function idempotencyKey(int $restaurantId, string $period): string
    {
        return sprintf('fairplate-statement-%d-%s', $restaurantId, $period);
    }

    /**
     * Net 7 from the first of the billing month's successor, in the billing
     * zone — the day the invoice is raised, plus seven.
     */
    private function dueDate(string $period): string
    {
        $issued = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $period . '-01 00:00:00',
            new \DateTimeZone('America/New_York')
        );

        if ($issued === false) {
            throw new \InvalidArgumentException("Billing period \"{$period}\" is not a valid YYYY-MM month.");
        }

        return $issued->modify('+1 month')->modify('+' . self::NET_DAYS . ' days')->format('Y-m-d');
    }

    /**
     * The restaurant's phone, or the owner's.
     *
     * A restaurant line is the better number — it is answered during service
     * and it is not one person's mobile — but a kitchen that only gave us its
     * owner's number should still hear that its month was free.
     */
    private function text(array $restaurant, string $message): void
    {
        $phone = trim((string) ($restaurant['phone'] ?? ''));

        if ($phone === '') {
            foreach (RestaurantStaff::forRestaurant((int) $restaurant['id']) as $member) {
                $phone = trim((string) ($member['phone'] ?? ''));

                if ($phone !== '') {
                    break;
                }
            }
        }

        $normalized = $phone === '' ? null : Sms::normalize($phone);

        if ($normalized === null) {
            return;
        }

        Sms::send($normalized, $message);
    }

    /**
     * A URL Stripe may not have filled in yet, as a string or null.
     */
    private function urlOf(object $invoice, string $property): ?string
    {
        $value = trim((string) ($invoice->$property ?? ''));

        return $value === '' ? null : $value;
    }

    private function stripe(): StripeClient
    {
        return StripeClientFactory::make();
    }
}
