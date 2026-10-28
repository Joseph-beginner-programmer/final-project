<?php

namespace App\Support\Reports;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PurchaseOrderReport
{
    public function __construct(
        public readonly CarbonInterface $start,
        public readonly CarbonInterface $end,
    ) {}

    /**
     * @return Collection<int, array{po: PurchaseOrder, po_number: string, supplier: string, order_date: CarbonInterface, expected_delivery_date: ?CarbonInterface, status: PurchaseOrderStatus, items_count: int, total_amount: string, received_rate: ?float, overdue: bool}>
     */
    public function rows(): Collection
    {
        return PurchaseOrder::query()
            ->whereBetween('order_date', [$this->start->toDateString(), $this->end->toDateString()])
            ->with(['supplier', 'items'])
            ->orderBy('order_date')
            ->orderBy('id')
            ->get()
            ->map(function (PurchaseOrder $po) {
                $orderedValue = $po->items->reduce(fn (string $carry, PurchaseOrderItem $item) => bcadd($carry, (string) $item->subtotal, 2), '0');
                $receivedValue = $po->items->reduce(fn (string $carry, PurchaseOrderItem $item) => bcadd($carry, bcmul($item->netReceived(), (string) $item->unit_price, 2), 2), '0');

                return [
                    'po' => $po,
                    'po_number' => $po->po_number,
                    'supplier' => $po->supplier->supplier_name,
                    'order_date' => $po->order_date,
                    'expected_delivery_date' => $po->expected_delivery_date,
                    'status' => $po->status,
                    'items_count' => $po->items->count(),
                    'total_amount' => (string) $po->total_amount,
                    'received_rate' => bccomp($orderedValue, '0', 2) > 0 ? (float) $receivedValue / (float) $orderedValue * 100 : null,
                    'overdue' => in_array($po->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived], true)
                        && $po->expected_delivery_date !== null
                        && $po->expected_delivery_date->lt(now()->startOfDay()),
                ];
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count: int, items_count: int, total_amount: string, overdue: int}
     */
    public function sum(Collection $rows): array
    {
        return [
            'count' => $rows->count(),
            'items_count' => $rows->sum('items_count'),
            'total_amount' => $rows->reduce(fn (string $carry, array $row) => bcadd($carry, (string) $row['total_amount'], 2), '0'),
            'overdue' => $rows->where('overdue', true)->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array{status: PurchaseOrderStatus, count: int, total_amount: string}>
     */
    public function statusSummary(Collection $rows): Collection
    {
        return $rows->groupBy(fn (array $row) => $row['status']->value)
            ->map(fn (Collection $group) => [
                'status' => $group->first()['status'],
                'count' => $group->count(),
                'total_amount' => $group->reduce(fn (string $carry, array $row) => bcadd($carry, (string) $row['total_amount'], 2), '0'),
            ])
            ->sortByDesc('count')
            ->values();
    }

    /**
     * @return array{header: list<string>, rows: list<list<string>>}
     */
    public function excel(): array
    {
        $rows = $this->rows();
        $lines = $rows->map(fn (array $r) => [
            $r['po_number'], $r['supplier'], $r['order_date']->format('Y-m-d'),
            $r['expected_delivery_date']?->format('Y-m-d') ?? '', $r['status']->label(),
            (string) $r['items_count'], Excel::number($r['total_amount']),
            $r['received_rate'] === null ? '' : Excel::number($r['received_rate'], 1), $r['overdue'] ? __('Overdue') : '',
        ])->all();
        $t = $this->sum($rows);
        $lines[] = [__('Total'), '', '', '', '', (string) $t['items_count'], Excel::number($t['total_amount']), '', ''];

        return [
            'header' => [__('PO'), __('Supplier'), __('Order date'), __('Expected delivery'), __('Status'), __('Items'), __('Total amount'), __('Received'), __('Overdue')],
            'rows' => $lines,
        ];
    }
}
