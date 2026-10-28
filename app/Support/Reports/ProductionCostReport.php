<?php

namespace App\Support\Reports;

use App\Enums\DocumentStatus;
use App\Enums\ProductType;
use App\Models\ProductionResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ProductionCostReport
{
    public function __construct(
        public readonly CarbonInterface $start,
        public readonly CarbonInterface $end,
        public readonly ?ProductType $type = null,
    ) {}

    /**
     * @return Collection<int, array{result: ProductionResult, wo_number: string, product_id: int, product: string, completed_on: CarbonInterface, good: string, material: string, labor: string, overhead: string, total: string, unit_cost: ?string, planned_conversion: string, difference: string}>
     */
    public function rows(): Collection
    {
        return ProductionResult::query()
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('production_date', [$this->start->toDateString(), $this->end->toDateString()])
            ->when($this->type, fn ($q) => $q->whereHas('workOrder.product', fn ($q) => $q->where('type', $this->type->value)))
            ->with(['workOrder.product', 'workOrder.labors'])
            ->orderBy('production_date')
            ->orderBy('id')
            ->get()
            ->map(function (ProductionResult $result) {
                $wo = $result->workOrder;
                $plannedLabor = $wo->labors->reduce(fn (string $carry, $labor) => bcadd($carry, (string) $labor->planned_cost, 2), '0');
                $plannedConversion = bcadd($plannedLabor, (string) $wo->planned_overhead_cost, 2);
                $labor = bcadd((string) $result->labor_cost, '0', 2);
                $overhead = bcadd((string) $result->overhead_cost, '0', 2);

                return [
                    'result' => $result,
                    'wo_number' => $wo->wo_number,
                    'product_id' => $wo->product_id,
                    'product' => $wo->product->product_name,
                    'completed_on' => $result->production_date,
                    'good' => bcadd((string) $result->quantity_good, '0', 2),
                    'material' => bcadd((string) $result->material_cost, '0', 2),
                    'labor' => $labor,
                    'overhead' => $overhead,
                    'total' => bcadd((string) $result->total_cost, '0', 2),
                    'unit_cost' => $result->unit_cost !== null ? bcadd((string) $result->unit_cost, '0', 2) : null,
                    'planned_conversion' => $plannedConversion,
                    'difference' => bcsub(bcadd($labor, $overhead, 2), $plannedConversion, 2),
                ];
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array{product: string, work_orders: int, good: string, material: string, labor: string, overhead: string, total: string, average_unit_cost: ?string, lowest_unit_cost: ?string, highest_unit_cost: ?string}>
     */
    public function productSummary(Collection $rows): Collection
    {
        return $rows->groupBy('product_id')
            ->map(function (Collection $group) {
                $totals = $this->sum($group);
                $unitCosts = $group->pluck('unit_cost')->filter(fn ($v) => $v !== null);

                return [
                    'product' => $group->first()['product'],
                    'work_orders' => $group->count(),
                    ...$totals,
                    'average_unit_cost' => bccomp($totals['good'], '0', 2) > 0 ? bcdiv($totals['total'], $totals['good'], 2) : null,
                    'lowest_unit_cost' => $unitCosts->isNotEmpty() ? $unitCosts->reduce(fn ($min, $v) => $min === null || bccomp($v, $min, 2) < 0 ? $v : $min) : null,
                    'highest_unit_cost' => $unitCosts->isNotEmpty() ? $unitCosts->reduce(fn ($max, $v) => $max === null || bccomp($v, $max, 2) > 0 ? $v : $max) : null,
                ];
            })
            ->sortByDesc(fn ($row) => (float) $row['total'])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{good: string, material: string, labor: string, overhead: string, total: string, difference: string}
     */
    public function sum(Collection $rows): array
    {
        $add = fn (string $field) => $rows->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row[$field], 2), '0');

        return [
            'good' => $add('good'),
            'material' => $add('material'),
            'labor' => $add('labor'),
            'overhead' => $add('overhead'),
            'total' => $add('total'),
            'difference' => $add('difference'),
        ];
    }

    public static function share(string $part, string $total): ?float
    {
        return bccomp($total, '0', 2) > 0 ? (float) $part / (float) $total * 100 : null;
    }

    /**
     * @return array{header: list<string>, rows: list<list<string>>}
     */
    public function excel(): array
    {
        $rows = $this->rows();
        $lines = $rows->map(fn ($r) => [
            $r['wo_number'], $r['product'], $r['completed_on']->format('Y-m-d'), Excel::number($r['good']),
            Excel::number($r['material']), Excel::number($r['labor']), Excel::number($r['overhead']), Excel::number($r['total']),
            Excel::number($r['unit_cost']), Excel::number($r['planned_conversion']), Excel::number($r['difference']),
        ])->all();
        $t = $this->sum($rows);
        $lines[] = [__('Total'), '', '', Excel::number($t['good']), Excel::number($t['material']), Excel::number($t['labor']), Excel::number($t['overhead']), Excel::number($t['total']), '', '', Excel::number($t['difference'])];
        $lines[] = [];
        $lines[] = [__('Summary per product'), __('Work orders'), __('Good units'), __('Material'), __('Labor'), __('Overhead'), __('Total cost'), __('Average unit cost'), __('Lowest'), __('Highest')];
        foreach ($this->productSummary($rows) as $p) {
            $lines[] = [$p['product'], (string) $p['work_orders'], Excel::number($p['good']), Excel::number($p['material']), Excel::number($p['labor']), Excel::number($p['overhead']), Excel::number($p['total']), Excel::number($p['average_unit_cost']), Excel::number($p['lowest_unit_cost']), Excel::number($p['highest_unit_cost'])];
        }

        return [
            'header' => [__('Work order'), __('Product'), __('Completed on'), __('Good units'), __('Material'), __('Labor'), __('Overhead'), __('Total cost'), __('Unit cost'), __('Planned labor + overhead'), __('Difference vs plan')],
            'rows' => $lines,
        ];
    }
}
