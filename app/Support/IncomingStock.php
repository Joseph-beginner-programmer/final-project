<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\WorkOrderStatus;
use App\Models\ProductionResult;
use App\Models\PurchaseOrderItem;
use App\Models\WorkOrder;

class IncomingStock
{
    /**
     * @param  iterable<int>  $productIds
     * @return array<int, list<array{source: string, number: string, party: string, quantity: string, date: ?\Carbon\CarbonInterface, note: string, ready: bool, document: \Illuminate\Database\Eloquent\Model}>>
     */
    public static function forProducts(iterable $productIds, ?int $exceptWorkOrderId = null): array
    {
        $productIds = collect($productIds)->values()->all();
        $rows = [];

        $poItems = PurchaseOrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::PartiallyReceived->value]))
            ->with('purchaseOrder.supplier')
            ->get();
        foreach ($poItems as $item) {
            $open = $item->remainingQuantity();
            if (bccomp($open, '0', 2) > 0) {
                $rows[$item->product_id][] = [
                    'source' => 'purchase',
                    'number' => $item->purchaseOrder->po_number,
                    'party' => $item->purchaseOrder->supplier->supplier_name,
                    'quantity' => $open,
                    'date' => $item->purchaseOrder->expected_delivery_date,
                    'note' => $item->purchaseOrder->status->label(),
                    'ready' => false,
                    'document' => $item->purchaseOrder,
                ];
            }
        }

        $workOrders = WorkOrder::query()
            ->whereIn('product_id', $productIds)
            ->when($exceptWorkOrderId, fn ($q) => $q->whereKeyNot($exceptWorkOrderId))
            ->whereIn('status', [WorkOrderStatus::Released->value, WorkOrderStatus::InProgress->value])
            ->with('workCenter')
            ->get();
        foreach ($workOrders as $wo) {
            $rows[$wo->product_id][] = [
                'source' => 'production',
                'number' => $wo->wo_number,
                'party' => __($wo->workCenter->name),
                'quantity' => (string) $wo->quantity_target,
                'date' => $wo->planned_end_date,
                'note' => $wo->status->label(),
                'ready' => false,
                'document' => $wo,
            ];
        }

        $awaitingReceipt = ProductionResult::query()
            ->where('status', DocumentStatus::Posted->value)
            ->whereDoesntHave('productionReceipts', fn ($q) => $q->where('status', DocumentStatus::Posted->value))
            ->whereHas('workOrder', fn ($q) => $q->whereIn('product_id', $productIds))
            ->with('workOrder')
            ->get();
        foreach ($awaitingReceipt as $result) {
            $rows[$result->workOrder->product_id][] = [
                'source' => 'production',
                'number' => $result->workOrder->wo_number,
                'party' => $result->result_number,
                'quantity' => (string) $result->quantity_good,
                'date' => $result->production_date,
                'note' => __('Finished, waiting for the warehouse'),
                'ready' => true,
                'document' => $result->workOrder,
            ];
        }

        foreach ($rows as &$list) {
            usort($list, fn ($a, $b) => ($a['date']?->timestamp ?? PHP_INT_MAX) <=> ($b['date']?->timestamp ?? PHP_INT_MAX));
        }

        return $rows;
    }

    /**
     * @param  list<array{quantity: string}>  $rows
     */
    public static function total(array $rows): string
    {
        return array_reduce($rows, fn (string $carry, array $row) => bcadd($carry, $row['quantity'], 2), '0');
    }
}
