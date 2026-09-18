<?php

namespace Keel\App\Controllers\Kitchen;

use Keel\App\Models\MenuItem;
use Keel\App\Models\Order;
use Keel\App\Models\OrderItem;
use Keel\App\Models\Restaurant;
use Keel\App\Models\RestaurantFeeTier;
use Keel\App\Services\OrderLifecycle;
use Keel\App\Services\OrderLifecycleException;
use Keel\App\Services\Pricing\Money;
use Keel\Core\Request;
use Keel\Core\View;

/**
 * The live board, and the three buttons on it.
 *
 * The board is server-rendered and stays that way. The poll at /kitchen/orders/
 * feed asks for the same board markup back with a signature attached, and the
 * page swaps it in only when the signature changed. That costs one small
 * request every five seconds and keeps every card's markup in a view file where
 * it can be read, instead of in a template string inside a script.
 *
 * Accept and Reject are plain forms inside a <details>, not a modal. Two
 * reasons: a disclosure needs no JavaScript, so the board still works if the
 * script fails to load on a tablet that has been awake for three weeks; and it
 * puts the prep times one tap away instead of two, which is the difference
 * between a busy Friday and an argument about the tablet.
 */
class OrdersController extends KitchenController
{
    /** The columns, and which statuses land in each. */
    public const COLUMNS = [
        'new' => ['label' => 'New', 'statuses' => [Order::STATUS_PLACED]],
        'in_progress' => ['label' => 'In Progress', 'statuses' => [Order::STATUS_ACCEPTED]],
        'ready' => ['label' => 'Ready', 'statuses' => [Order::STATUS_READY, Order::STATUS_DRIVER_ASSIGNED]],
    ];

    public function index(Request $request): void
    {
        $restaurant = $this->currentRestaurant();

        $this->view('kitchen.orders', array_merge(
            $this->shell($restaurant, 'orders', 'Orders'),
            $this->boardData($restaurant),
            [
                // The 86 switch belongs on this screen as well as the menu one:
                // the moment a kitchen runs out mid-rush is the moment nobody is
                // walking over to another tab to say so.
                'menuItems' => $restaurant === null ? [] : MenuItem::forRestaurant((int) $restaurant['id']),
            ]
        ));
    }

    /**
     * The board again, as JSON, for the five-second poll.
     *
     * The signature is what the page compares: while it is unchanged there is
     * nothing to redraw, and a kitchen mid-tap does not get the ground moved
     * under it. new_order_ids is what decides whether the chime rings, and it is
     * the server's answer rather than the page's, so a tablet that was asleep
     * wakes up ringing for the orders it missed.
     */
    public function feed(Request $request): void
    {
        $restaurant = $this->currentRestaurant();

        if ($restaurant === null) {
            $this->json(['signature' => 'none', 'new_order_ids' => [], 'html' => '']);
        }

        $data = $this->boardData($restaurant);

        $this->json([
            'signature' => $data['signature'],
            'new_order_ids' => $data['newOrderIds'],
            'paused' => Restaurant::isPaused($restaurant),
            'html' => $this->renderBoard($data),
        ]);
    }

    public function accept(Request $request, string $id): void
    {
        $orderId = (int) $id;
        $this->authorizeRecord('orders', $orderId);

        $prepMinutes = (int) $request->input('prep_minutes', 0);

        try {
            OrderLifecycle::accept($orderId, $prepMinutes);
        } catch (OrderLifecycleException $exception) {
            $this->back('/kitchen', $this->staleMessage($exception), 'bad');
        }

        $this->back('/kitchen', "Order #{$orderId} accepted, {$prepMinutes} minutes.");
    }

    public function reject(Request $request, string $id): void
    {
        $orderId = (int) $id;
        $this->authorizeRecord('orders', $orderId);

        $reason = (string) $request->input('reason', '');
        $note = (string) $request->input('note', '');

        try {
            OrderLifecycle::reject($orderId, $reason, $note);
        } catch (OrderLifecycleException $exception) {
            $this->back('/kitchen', $this->staleMessage($exception), 'bad');
        }

        $this->back('/kitchen', "Order #{$orderId} rejected. The customer has been released.");
    }

    public function ready(Request $request, string $id): void
    {
        $orderId = (int) $id;
        $this->authorizeRecord('orders', $orderId);

        try {
            OrderLifecycle::markReady($orderId);
        } catch (OrderLifecycleException $exception) {
            $this->back('/kitchen', $this->staleMessage($exception), 'bad');
        }

        $this->back('/kitchen', "Order #{$orderId} is ready for pickup.");
    }

    /**
     * This month's orders, tier and fee.
     *
     * The numbers are real where phase 2 already provides them — the completed
     * count and the tier table are both live — and the projected fee is
     * arithmetic on those two. What is not here is anything from a statement,
     * because statements are phase 7; the panel says so rather than showing a
     * confident zero.
     */
    public function month(Request $request): void
    {
        $restaurant = $this->requireRestaurant();
        $restaurantId = (int) $restaurant['id'];

        $period = (new \DateTimeImmutable('now', new \DateTimeZone('America/New_York')))->format('Y-m');
        $completed = Order::completedCountForPeriod($restaurantId, $period);
        $tiers = RestaurantFeeTier::all();
        $tier = RestaurantFeeTier::forOrderCount($completed);

        $this->view('kitchen.month', array_merge(
            $this->shell($restaurant, 'month', 'This month'),
            [
                'period' => $period,
                'completed' => $completed,
                'tier' => $tier,
                'tiers' => $tiers,
                'nextTier' => $this->nextTier($tiers, $tier),
                'projectedFeeCents' => $this->projectedFeeCents($restaurant, $tier),
            ]
        ));
    }

    /**
     * @return array{columns: array, signature: string, newOrderIds: list<int>, restaurant: array}
     */
    private function boardData(?array $restaurant): array
    {
        if ($restaurant === null) {
            return [
                'columns' => [],
                'signature' => 'none',
                'newOrderIds' => [],
                'boardRestaurant' => null,
            ];
        }

        $orders = Order::boardForRestaurant((int) $restaurant['id']);
        $columns = [];

        foreach (self::COLUMNS as $key => $column) {
            $columns[$key] = ['label' => $column['label'], 'orders' => []];
        }

        $newOrderIds = [];
        $signatureParts = [];

        foreach ($orders as $order) {
            $orderId = (int) $order['id'];
            $status = (string) $order['status'];
            $order['items'] = $this->itemsWithOptions($orderId);

            foreach (self::COLUMNS as $key => $column) {
                if (in_array($status, $column['statuses'], true)) {
                    $columns[$key]['orders'][] = $order;
                    break;
                }
            }

            if ($status === Order::STATUS_PLACED) {
                $newOrderIds[] = $orderId;
            }

            // Status and driver are the only things on a card that change
            // without the card being replaced, so they are the signature.
            $signatureParts[] = $orderId . ':' . $status . ':' . (string) ($order['driver_id'] ?? '');
        }

        return [
            'columns' => $columns,
            'signature' => $signatureParts === [] ? 'empty' : md5(implode('|', $signatureParts)),
            'newOrderIds' => $newOrderIds,
            'boardRestaurant' => $restaurant,
        ];
    }

    /**
     * Line items with their chosen options, which is what the card has to show
     * for the kitchen to actually cook the thing.
     */
    private function itemsWithOptions(int $orderId): array
    {
        $items = OrderItem::forOrder($orderId);

        foreach ($items as $index => $item) {
            $items[$index]['options'] = OrderItem::options((int) $item['id']);
        }

        return $items;
    }

    private function renderBoard(array $data): string
    {
        ob_start();

        try {
            View::render('kitchen.partials.board', $data);
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        return (string) ob_get_clean();
    }

    /**
     * The tier above the one a restaurant is in, so the panel can show what the
     * next threshold is. Null at the top tier.
     */
    private function nextTier(array $tiers, ?array $tier): ?array
    {
        if ($tier === null) {
            return $tiers[0] ?? null;
        }

        foreach ($tiers as $candidate) {
            if ((int) $candidate['min_orders'] > (int) $tier['min_orders']) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The tier fee with this restaurant's founding discount taken off.
     *
     * Null on the custom tier until an admin has set a figure, because the spec
     * is explicit that nothing is invoiced there until someone decides what it
     * is, and a made-up number on this panel would be a promise.
     */
    private function projectedFeeCents(array $restaurant, ?array $tier): ?int
    {
        if ($tier === null) {
            return null;
        }

        if ((int) ($tier['is_custom'] ?? 0) === 1) {
            $custom = $restaurant['custom_fee_cents'] ?? null;

            if ($custom === null) {
                return null;
            }

            $feeCents = (int) $custom;
        } else {
            $feeCents = (int) $tier['fee_cents'];
        }

        $discount = (string) ($restaurant['founding_discount_pct'] ?? '0');

        return $feeCents - Money::percentOf($feeCents, $discount);
    }

    /**
     * An illegal transition from a button means the board on this tablet was
     * showing something that is no longer true.
     */
    private function staleMessage(OrderLifecycleException $exception): string
    {
        error_log('[FairPlate] Kitchen order action refused: ' . $exception->getMessage());

        return 'That order moved on before the tap landed. The board has been refreshed.';
    }
}
