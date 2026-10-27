<?php

namespace App\Support\Kpi;

use App\Enums\DocumentStatus;
use App\Enums\ProductType;
use App\Enums\PurchaseRequisitionStatus;
use App\Enums\WorkOrderStatus;
use App\Models\MaterialIssueItem;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequisitionItem;
use App\Models\WorkOrder;
use App\Support\MaterialShortage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class OngoingWorkOrders
{
    public const STATE_SHORT = 'short';

    public const STATE_READY = 'ready';

    public const STATE_AWAITING_RESULT = 'awaiting_result';

    public const STATE_RESULT_DRAFT = 'result_draft';

    /**
     * @return list<string>
     */
    public static function openStatuses(): array
    {
        return [WorkOrderStatus::Draft->value, WorkOrderStatus::Released->value, WorkOrderStatus::InProgress->value];
    }

    /**
     * @return Collection<int, array{work_order: WorkOrder, state: string, overdue: bool, materials: Collection<int, array{product: Product, planned: string, issued: string, covered: string, short: string, incoming: string, incoming_from: list<string>, to_order: string}>}>
     */
    public static function all(CarbonInterface $today): Collection
    {
        $orders = WorkOrder::query()
            ->whereIn('status', self::openStatuses())
            ->with(['product', 'materials.product', 'productionResult'])
            ->orderBy('planned_start_date')
            ->orderBy('id')
            ->get();

        $issued = MaterialIssueItem::query()
            ->join('material_issues', 'material_issues.id', '=', 'material_issue_items.material_issue_id')
            ->where('material_issues.status', DocumentStatus::Posted->value)
            ->whereIn('material_issues.work_order_id', $orders->pluck('id'))
            ->groupBy('material_issues.work_order_id', 'material_issue_items.product_id')
            ->selectRaw('material_issues.work_order_id as wo, material_issue_items.product_id as product, SUM(material_issue_items.quantity) as qty')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->wo.'-'.$row->product => (string) $row->qty]);

        $materialIds = $orders->flatMap(fn (WorkOrder $wo) => $wo->materials->pluck('product_id'))->unique()->values();
        $pool = Product::whereIn('id', $materialIds)->pluck('current_stock', 'id')->map(fn ($v) => bcadd((string) $v, '0', 2))->all();

        $producing = $orders->groupBy('product_id');
        $onOrder = PurchaseOrderItem::query()
            ->whereIn('product_id', $materialIds)
            ->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', MaterialShortage::openPurchaseOrderStatuses()))
            ->with('purchaseOrder:id,po_number')
            ->get();
        $requested = PurchaseRequisitionItem::query()
            ->whereIn('product_id', $materialIds)
            ->whereHas('purchaseRequisition', fn ($q) => $q->where('status', PurchaseRequisitionStatus::Submitted->value))
            ->with('purchaseRequisition:id,requisition_number')
            ->get();

        return $orders->map(function (WorkOrder $wo) use ($issued, &$pool, $producing, $onOrder, $requested, $today) {
            $materials = $wo->materials->map(function ($material) use ($wo, $issued, &$pool, $producing, $onOrder, $requested) {
                $planned = bcadd((string) $material->quantity_planned, '0', 2);
                $done = $issued[$wo->id.'-'.$material->product_id] ?? '0';
                $remaining = self::positive(bcsub($planned, $done, 2));
                $available = self::positive($pool[$material->product_id] ?? '0');
                $covered = bccomp($available, $remaining, 2) >= 0 ? $remaining : $available;
                $pool[$material->product_id] = bcsub($available, $covered, 2);

                if ($material->product->type === ProductType::RawMaterial) {
                    $lines = $onOrder->where('product_id', $material->product_id);
                    $asked = $requested->where('product_id', $material->product_id);
                    $incoming = $lines->reduce(fn (string $carry, $item) => bcadd($carry, self::positive($item->remainingQuantity()), 2), '0');
                    $incoming = $asked->reduce(fn (string $carry, $item) => bcadd($carry, (string) $item->quantity, 2), $incoming);
                    $from = $lines->toBase()->map(fn ($item) => $item->purchaseOrder->po_number)
                        ->merge($asked->toBase()->map(fn ($item) => $item->purchaseRequisition->requisition_number))
                        ->unique()->values()->all();
                } else {
                    $makers = ($producing[$material->product_id] ?? collect())->where('id', '!=', $wo->id);
                    $incoming = $makers->reduce(fn (string $carry, WorkOrder $maker) => bcadd($carry, (string) $maker->quantity_target, 2), '0');
                    $from = $makers->pluck('wo_number')->values()->all();
                }

                $short = bcsub($remaining, $covered, 2);

                return [
                    'product' => $material->product,
                    'planned' => $planned,
                    'issued' => bccomp($done, $planned, 2) > 0 ? $planned : bcadd($done, '0', 2),
                    'covered' => $covered,
                    'short' => $short,
                    'incoming' => $incoming,
                    'incoming_from' => $from,
                    'to_order' => self::positive(bcsub($short, $incoming, 2)),
                ];
            });

            $remaining = $materials->contains(fn (array $m) => bccomp(bcadd($m['covered'], $m['short'], 2), '0', 2) > 0);

            return [
                'work_order' => $wo,
                'state' => match (true) {
                    $materials->contains(fn (array $m) => bccomp($m['short'], '0', 2) > 0) => self::STATE_SHORT,
                    $remaining || $wo->status !== WorkOrderStatus::InProgress => self::STATE_READY,
                    $wo->productionResult !== null => self::STATE_RESULT_DRAFT,
                    default => self::STATE_AWAITING_RESULT,
                },
                'overdue' => $wo->planned_end_date->lt($today->copy()->startOfDay()),
                'materials' => $materials,
            ];
        });
    }

    protected static function positive(string $value): string
    {
        return bccomp($value, '0', 2) > 0 ? $value : '0';
    }
}
