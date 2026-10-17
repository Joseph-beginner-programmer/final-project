<?php

namespace App\Support;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrderItem;

class SalesAvailability
{
    /**
     * @param  iterable<int>  $productIds
     * @return array<int, string>
     */
    public static function reserved(iterable $productIds, ?int $exceptSalesOrderId = null): array
    {
        return SalesOrderItem::query()
            ->whereIn('product_id', collect($productIds)->values()->all())
            ->whereHas('salesOrder', fn ($q) => $q
                ->when($exceptSalesOrderId, fn ($q) => $q->whereKeyNot($exceptSalesOrderId))
                ->whereIn('status', [SalesOrderStatus::Confirmed->value, SalesOrderStatus::PartiallyDelivered->value]))
            ->get()
            ->groupBy('product_id')
            ->map(fn ($items) => $items->reduce(fn (string $carry, SalesOrderItem $item) => bcadd($carry, $item->remainingQuantity(), 2), '0'))
            ->all();
    }

    /**
     * @param  iterable<int>  $productIds
     * @return array<int, list<array{source: string, number: string, party: string, quantity: string, date: ?\Carbon\CarbonInterface, note: string, ready: bool, document: \App\Models\SalesOrder}>>
     */
    public static function reservedOrders(iterable $productIds, ?int $exceptSalesOrderId = null): array
    {
        $rows = [];

        $items = SalesOrderItem::query()
            ->whereIn('product_id', collect($productIds)->values()->all())
            ->whereHas('salesOrder', fn ($q) => $q
                ->when($exceptSalesOrderId, fn ($q) => $q->whereKeyNot($exceptSalesOrderId))
                ->whereIn('status', [SalesOrderStatus::Confirmed->value, SalesOrderStatus::PartiallyDelivered->value]))
            ->with('salesOrder.customer')
            ->get();

        foreach ($items as $item) {
            $remaining = $item->remainingQuantity();
            if (bccomp($remaining, '0', 2) <= 0) {
                continue;
            }
            $rows[$item->product_id][] = [
                'source' => 'sales',
                'number' => $item->salesOrder->so_number,
                'party' => $item->salesOrder->customer->customer_name,
                'quantity' => $remaining,
                'date' => $item->salesOrder->expected_delivery_date,
                'note' => $item->salesOrder->status->label(),
                'ready' => false,
                'document' => $item->salesOrder,
            ];
        }

        foreach ($rows as &$list) {
            usort($list, fn ($a, $b) => ($a['date']?->timestamp ?? PHP_INT_MAX) <=> ($b['date']?->timestamp ?? PHP_INT_MAX));
        }

        return $rows;
    }

    /**
     * @return array{available: string, incoming_free: string, shortage: ?string, status: ?string}
     */
    public static function check(string $needed, string $stock, string $reserved, string $incoming): array
    {
        $available = self::positive(bcsub($stock, $reserved, 2));
        $reservedBeyondStock = self::positive(bcsub($reserved, $stock, 2));
        $incomingFree = self::positive(bcsub($incoming, $reservedBeyondStock, 2));
        $shortage = bcsub($needed, $available, 2);

        return [
            'available' => $available,
            'incoming_free' => $incomingFree,
            'shortage' => bccomp($shortage, '0', 2) > 0 ? $shortage : null,
            'status' => match (true) {
                bccomp($needed, '0', 2) <= 0 => null,
                bccomp($shortage, '0', 2) <= 0 => 'enough',
                bccomp(bcadd($available, $incomingFree, 2), $needed, 2) >= 0 => 'incoming',
                default => 'short',
            },
        ];
    }

    private static function positive(string $value): string
    {
        return bccomp($value, '0', 2) > 0 ? $value : '0';
    }
}
