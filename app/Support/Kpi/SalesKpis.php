<?php

namespace App\Support\Kpi;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use Illuminate\Support\Collection;

class SalesKpis
{
    /**
     * @return array{count: int, value: string, by_status: Collection<int, array{status: SalesOrderStatus, count: int, value: string}>}
     */
    public static function openOrders(): array
    {
        $orders = SalesOrder::with('items')
            ->whereIn('status', [SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyDelivered])
            ->get();

        $byStatus = $orders->groupBy(fn (SalesOrder $order) => $order->status->value)
            ->map(fn (Collection $rows, string $status) => [
                'status' => SalesOrderStatus::from($status),
                'count' => $rows->count(),
                'value' => $rows->reduce(fn (string $carry, SalesOrder $order) => bcadd($carry, self::remainingValue($order), 2), '0'),
            ])
            ->values();

        return [
            'count' => $orders->count(),
            'value' => $byStatus->reduce(fn (string $carry, array $row) => bcadd($carry, $row['value'], 2), '0'),
            'by_status' => $byStatus,
        ];
    }

    /**
     * @return Collection<int, array{order: SalesOrder, value: string}>
     */
    public static function oldestOpenOrders(int $limit = 5): Collection
    {
        return SalesOrder::with(['items', 'customer'])
            ->whereIn('status', [SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyDelivered])
            ->orderBy('order_date')
            ->take($limit)
            ->get()
            ->map(fn (SalesOrder $order) => ['order' => $order, 'value' => self::remainingValue($order)]);
    }

    protected static function remainingValue(SalesOrder $order): string
    {
        return $order->items->reduce(
            fn (string $carry, \App\Models\SalesOrderItem $item) => bcadd($carry, bcmul($item->remainingQuantity(), (string) $item->unit_price, 2), 2),
            '0',
        );
    }
}
