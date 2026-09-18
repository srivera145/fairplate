<?php

namespace Keel\App\Controllers\Drive;

use Keel\App\Models\Order;
use Keel\Core\Request;

/**
 * What the shift was worth, broken down far enough to check.
 *
 * Drivers here are independent and multi-app, which means this screen has a
 * competitor: the one in the other app, open on the same phone. The thing that
 * wins is not a bigger number, it is a number that can be verified. So every
 * delivery shows base, miles, wait and tip separately, and the totals are sums
 * of those lines rather than a figure arrived at somewhere else.
 *
 * Nothing on this screen is computed here. Each line was priced by
 * PricingService when the order was delivered and frozen in the final
 * breakdown; this adds up the columns and shows them.
 *
 * Where base plus miles comes to less than the guarantee, the difference is the
 * minimum payout and the view says so. That is the line drivers ask about — a
 * half-mile run that pays five dollars looks like an arithmetic error until
 * somebody explains the floor, and the screen should be the thing that explains
 * it.
 */
class EarningsController extends DriverController
{
    public function index(Request $request): void
    {
        $driver = $this->requireDriverProfile();
        $driverId = (int) $driver['id'];

        [$dayStart, $dayEnd] = $this->dayBoundsUtc();
        [$weekStart, $weekEnd] = $this->weekBoundsUtc();

        $week = Order::driverEarnings($driverId, $weekStart, $weekEnd);

        $this->view('drive.earnings', array_merge(
            $this->shell($driver, 'earnings', 'Earnings'),
            [
                'today' => $this->summarise(Order::driverEarnings($driverId, $dayStart, $dayEnd)),
                'week' => $this->summarise($week),
                'weekStartsOn' => $this->localNow()->setTime(0, 0, 0)->modify('monday this week'),
                // The week's runs are the history: far enough back to answer
                // "what did Tuesday pay", short enough to load on a phone.
                'deliveries' => $this->withDetail($week),
            ]
        ));
    }

    /**
     * The four lines and their total, for one set of deliveries.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function summarise(array $rows): array
    {
        $summary = [
            'count' => 0,
            'base_cents' => 0,
            'mileage_cents' => 0,
            'guaranteed_cents' => 0,
            'wait_cents' => 0,
            'tip_cents' => 0,
            'total_cents' => 0,
        ];

        foreach ($rows as $row) {
            $summary['count']++;
            $summary['base_cents'] += (int) $row['driver_base_cents'];
            $summary['mileage_cents'] += (int) $row['driver_mileage_cents'];
            $summary['guaranteed_cents'] += (int) $row['driver_guaranteed_cents'];
            $summary['wait_cents'] += (int) $row['wait_pay_cents'];
            $summary['tip_cents'] += (int) $row['tip_cents'];
            $summary['total_cents'] += (int) $row['driver_guaranteed_cents']
                + (int) $row['wait_pay_cents']
                + (int) $row['tip_cents'];
        }

        return $summary;
    }

    /**
     * Each delivery with its own total and whether the minimum payout carried
     * it.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withDetail(array $rows): array
    {
        foreach ($rows as $index => $row) {
            $guaranteed = (int) $row['driver_guaranteed_cents'];
            $built = (int) $row['driver_base_cents'] + (int) $row['driver_mileage_cents'];

            $rows[$index]['total_cents'] = $guaranteed
                + (int) $row['wait_pay_cents']
                + (int) $row['tip_cents'];
            $rows[$index]['minimum_topped_up_cents'] = max(0, $guaranteed - $built);
        }

        return $rows;
    }
}
