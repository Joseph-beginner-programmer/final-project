<?php

namespace App\Support;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequisitionStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequisitionItem;
use App\Models\WorkOrderMaterial;
use Illuminate\Support\Collection;

class MaterialShortage
{
    /**
     * @return Collection<int, array{product: Product, needed: string, stock: string, on_order: string, requested: string, shortage: string, reorder_gap: string, suggested: string, work_orders: list<string>}>
     */
    public static function suggestions(): Collection
    {
        $planned = WorkOrderMaterial::query()
            ->whereHas('workOrder', fn ($q) => $q->where('status', WorkOrderStatus::Draft->value))
            ->with('workOrder:id,wo_number')
            ->get();

        $needed = [];
        $workOrders = [];
        foreach ($planned as $line) {
            $remaining = (string) $line->quantity_planned;
            if (bccomp($remaining, '0', 2) > 0) {
                $needed[$line->product_id] = bcadd($needed[$line->product_id] ?? '0', $remaining, 2);
                $workOrders[$line->product_id][] = $line->workOrder->wo_number;
            }
        }

        $onOrder = PurchaseOrderItem::query()
            ->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', self::openPurchaseOrderStatuses()))
            ->get(['product_id', 'quantity_ordered', 'quantity_received', 'quantity_returned'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->reduce(function (string $carry, $row) {
                $open = bcsub((string) $row->quantity_ordered, bcsub((string) $row->quantity_received, (string) $row->quantity_returned, 2), 2);

                return bccomp($open, '0', 2) > 0 ? bcadd($carry, $open, 2) : $carry;
            }, '0'));

        $requested = PurchaseRequisitionItem::query()
            ->whereHas('purchaseRequisition', fn ($q) => $q->where('status', PurchaseRequisitionStatus::Submitted->value))
            ->get(['product_id', 'quantity'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->quantity, 2), '0'));

        return Product::purchasable()
            ->where('is_active', true)
            ->orderBy('product_code')
            ->get()
            ->map(function (Product $product) use ($needed, $workOrders, $onOrder, $requested) {
                $need = $needed[$product->id] ?? '0';
                $stock = bcadd((string) $product->current_stock, '0', 2);
                $ordered = $onOrder[$product->id] ?? '0';
                $already = $requested[$product->id] ?? '0';
                $covered = bcadd(bcadd($stock, $ordered, 2), $already, 2);
                $shortage = self::positive(bcsub($need, $covered, 2));
                $reorderGap = bccomp((string) $product->reorder_point, '0', 2) > 0
                    ? self::positive(bcsub((string) $product->reorder_point, $covered, 2))
                    : '0';

                return [
                    'product' => $product,
                    'needed' => $need,
                    'stock' => $stock,
                    'on_order' => $ordered,
                    'requested' => $already,
                    'shortage' => $shortage,
                    'reorder_gap' => $reorderGap,
                    'suggested' => bccomp($shortage, $reorderGap, 2) >= 0 ? $shortage : $reorderGap,
                    'work_orders' => array_values(array_unique($workOrders[$product->id] ?? [])),
                ];
            })
            ->filter(fn (array $row) => bccomp($row['suggested'], '0', 2) > 0)
            ->sortByDesc(fn (array $row) => (float) $row['suggested'])
            ->values();
    }

    /**
     * @return list<string>
     */
    public static function openPurchaseOrderStatuses(): array
    {
        return array_map(fn (PurchaseOrderStatus $s) => $s->value, [
            PurchaseOrderStatus::Draft, PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived,
        ]);
    }

    protected static function positive(string $value): string
    {
        return bccomp($value, '0', 2) > 0 ? $value : '0';
    }
}
