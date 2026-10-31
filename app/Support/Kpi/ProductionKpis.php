<?php

namespace App\Support\Kpi;

use App\Enums\DocumentStatus;
use App\Enums\ProductType;
use App\Enums\SalesOrderStatus;
use App\Enums\WorkOrderStatus;
use App\Models\MaterialReturnItem;
use App\Models\ProductionFormula;
use App\Models\ProductionReceipt;
use App\Models\ProductionResult;
use App\Models\SalesOrder;
use App\Models\WorkCenter;
use App\Models\WorkOrder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ProductionKpis
{
    public const MAX_PRODUCT_SERIES = 5;

    public function __construct(
        public readonly CarbonInterface $start,
        public readonly CarbonInterface $end,
        public readonly ?ProductType $type = null,
    ) {}

    public function forPeriod(CarbonInterface $start, CarbonInterface $end): self
    {
        return new self($start, $end, $this->type);
    }

    /**
     * @return Collection<int, array{result: ProductionResult, planned_cost: string, actual_cost: string, cost_difference: string, reject_rate: float, materials_over_plan: int, over_plan: bool, on_time: bool}>
     */
    public function completedWorkOrders(): Collection
    {
        return $this->postedResults()
            ->with(['workOrder.product', 'workOrder.labors', 'workOrder.materials', 'materials'])
            ->orderBy('production_date')
            ->get()
            ->map(function (ProductionResult $result) {
                $wo = $result->workOrder;
                $plannedLabor = $wo->labors->reduce(fn (string $carry, $labor) => bcadd($carry, (string) $labor->planned_cost, 2), '0');
                $plannedCost = bcadd($plannedLabor, (string) $wo->planned_overhead_cost, 2);
                $actualCost = bcadd((string) $result->labor_cost, (string) $result->overhead_cost, 2);

                $used = $result->materials->groupBy('product_id')->map(fn ($rows) => $rows->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->quantity, 2), '0'));
                $materialsOverPlan = $wo->materials->filter(fn ($planned) => bccomp($used[$planned->product_id] ?? '0', (string) $planned->quantity_planned, 2) > 0)->count();

                $total = (float) $result->quantity_good + (float) $result->quantity_reject;
                $costDifference = bcsub($actualCost, $plannedCost, 2);

                return [
                    'result' => $result,
                    'planned_cost' => $plannedCost,
                    'actual_cost' => $actualCost,
                    'cost_difference' => $costDifference,
                    'reject_rate' => $total > 0 ? (float) $result->quantity_reject / $total * 100 : 0.0,
                    'materials_over_plan' => $materialsOverPlan,
                    'over_plan' => bccomp($costDifference, '0', 2) > 0 || $materialsOverPlan > 0,
                    'on_time' => $result->production_date->lte($wo->planned_end_date),
                ];
            });
    }

    /**
     * @return array{good: string, reject: string, reject_rate: float}
     */
    public function output(): array
    {
        $totals = $this->postedResults()
            ->selectRaw('COALESCE(SUM(quantity_good), 0) as good, COALESCE(SUM(quantity_reject), 0) as reject')
            ->first();

        $good = (float) $totals->good;
        $reject = (float) $totals->reject;

        return [
            'good' => (string) $totals->good,
            'reject' => (string) $totals->reject,
            'reject_rate' => $good + $reject > 0 ? $reject / ($good + $reject) * 100 : 0.0,
        ];
    }

    /**
     * @return array{material_variance: string, production_loss: string, total: string}
     */
    public function losses(): array
    {
        $materialVariance = (string) MaterialReturnItem::query()
            ->whereHas('materialReturn', fn (Builder $q) => $this->postedInPeriod($q)
                ->when($this->type, fn (Builder $q) => $q->whereHas('workOrder.product', fn (Builder $q) => $q->where('type', $this->type->value))))
            ->sum('variance_value');

        $productionLoss = (string) $this->postedInPeriod(ProductionReceipt::query())
            ->when($this->type, fn (Builder $q) => $q->whereHas('product', fn (Builder $q) => $q->where('type', $this->type->value)))
            ->sum('shortage_value');

        return [
            'material_variance' => bcadd($materialVariance, '0', 2),
            'production_loss' => bcadd($productionLoss, '0', 2),
            'total' => bcadd($materialVariance, $productionLoss, 2),
        ];
    }

    /**
     * @return Collection<int, array{id: int, name: string, good: float, reject: float}>
     */
    public function perProductTotals(): Collection
    {
        return $this->postedResults()
            ->join('work_orders', 'work_orders.id', '=', 'production_results.work_order_id')
            ->join('products', 'products.id', '=', 'work_orders.product_id')
            ->groupBy('products.id', 'products.product_name')
            ->selectRaw('products.id as id, products.product_name as name, SUM(production_results.quantity_good) as good, SUM(production_results.quantity_reject) as reject')
            ->get()
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'good' => (float) $row->good, 'reject' => (float) $row->reject])
            ->values();
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    public function seriesProducts(): Collection
    {
        return $this->perProductTotals()
            ->sortByDesc('good')
            ->take(self::MAX_PRODUCT_SERIES)
            ->sortBy('id')
            ->map(fn ($row) => ['id' => $row['id'], 'name' => $row['name']])
            ->values();
    }

    /**
     * @return list<array{label: string, start: CarbonInterface, end: CarbonInterface}>
     */
    public function weeks(): array
    {
        $weeks = [];
        $cursor = $this->start->copy()->startOfDay();

        while ($cursor->lte($this->end)) {
            $weekEnd = $cursor->copy()->endOfWeek(CarbonInterface::SUNDAY)->min($this->end->copy()->endOfDay());
            $weeks[] = [
                'label' => $cursor->isSameDay($weekEnd) ? $cursor->translatedFormat('j M') : $cursor->format('j').'–'.$weekEnd->translatedFormat('j M'),
                'start' => $cursor->copy(),
                'end' => $weekEnd->copy(),
            ];
            $cursor = $weekEnd->copy()->addDay()->startOfDay();
        }

        return $weeks;
    }

    /**
     * @param  Collection<int, array{id: int, name: string}>  $products
     * @return array{weeks: list<string>, series: list<array{id: int|string, name: string, values: list<float>, targets: list<float>}>}
     */
    public function weeklyOutput(Collection $products): array
    {
        $weeks = $this->weeks();
        $productIds = $products->pluck('id')->all();
        $weekIndex = function (CarbonInterface $date) use ($weeks): ?int {
            foreach ($weeks as $i => $week) {
                if ($date->betweenIncluded($week['start'], $week['end'])) {
                    return $i;
                }
            }

            return null;
        };

        $blank = array_fill(0, count($weeks), 0.0);
        $series = $products->mapWithKeys(fn ($p) => [$p['id'] => ['id' => $p['id'], 'name' => $p['name'], 'values' => $blank, 'targets' => $blank]])->all();
        $other = ['id' => 'other', 'name' => __('Others'), 'values' => $blank, 'targets' => $blank];

        foreach ($this->postedResults()->with('workOrder')->get() as $result) {
            $i = $weekIndex($result->production_date);
            if ($i === null) {
                continue;
            }
            $productId = $result->workOrder->product_id;
            if (in_array($productId, $productIds, true)) {
                $series[$productId]['values'][$i] += (float) $result->quantity_good;
            } else {
                $other['values'][$i] += (float) $result->quantity_good;
            }
        }

        $targetOrders = WorkOrder::query()
            ->where('status', '!=', WorkOrderStatus::Cancelled->value)
            ->whereBetween('planned_end_date', [$this->start->toDateString(), $this->end->toDateString()])
            ->when($this->type, fn (Builder $q) => $q->whereHas('product', fn (Builder $q) => $q->where('type', $this->type->value)))
            ->get(['product_id', 'quantity_target', 'planned_end_date']);

        foreach ($targetOrders as $wo) {
            $i = $weekIndex($wo->planned_end_date);
            if ($i === null) {
                continue;
            }
            if (in_array($wo->product_id, $productIds, true)) {
                $series[$wo->product_id]['targets'][$i] += (float) $wo->quantity_target;
            } else {
                $other['targets'][$i] += (float) $wo->quantity_target;
            }
        }

        $series = array_values($series);
        if (array_sum($other['values']) > 0 || array_sum($other['targets']) > 0) {
            $series[] = $other;
        }

        return ['weeks' => array_column($weeks, 'label'), 'series' => $series];
    }

    /**
     * @param  Collection<int, array{id: int, name: string}>  $products
     * @return array{months: list<string>, overall: list<?float>, series: list<array{id: int, name: string, values: list<?float>}>}
     */
    public function rejectTrend(Collection $products, int $months = 6): array
    {
        $labels = [];
        $overall = [];
        $perProduct = $products->mapWithKeys(fn ($p) => [$p['id'] => ['id' => $p['id'], 'name' => $p['name'], 'values' => []]])->all();

        foreach ($this->trailingMonths($months) as $month) {
            $labels[] = $month->start->translatedFormat('M Y');
            $totals = $month->perProductTotals()->keyBy('id');
            $good = $totals->sum('good');
            $reject = $totals->sum('reject');
            $overall[] = $good + $reject > 0 ? $reject / ($good + $reject) * 100 : null;

            foreach ($perProduct as $id => $row) {
                $t = $totals->get($id);
                $perProduct[$id]['values'][] = $t && $t['good'] + $t['reject'] > 0 ? $t['reject'] / ($t['good'] + $t['reject']) * 100 : null;
            }
        }

        return ['months' => $labels, 'overall' => $overall, 'series' => array_values($perProduct)];
    }

    /**
     * @return Collection<int, array{name: string, actual: float, budget: ?float, utilisation: ?float}>
     */
    public function workCenterUtilisation(): Collection
    {
        $actual = ProductionResult::query()
            ->where('production_results.status', DocumentStatus::Posted->value)
            ->whereBetween('production_date', [$this->start->toDateString(), $this->end->toDateString()])
            ->join('work_orders', 'work_orders.id', '=', 'production_results.work_order_id')
            ->groupBy('work_orders.work_center_id')
            ->selectRaw('work_orders.work_center_id as work_center_id, SUM(production_results.machine_hours) as hours')
            ->pluck('hours', 'work_center_id');

        return WorkCenter::query()
            ->orderBy('id')
            ->get()
            ->map(function (WorkCenter $center) use ($actual) {
                $hours = (float) ($actual[$center->id] ?? 0);
                $rate = $center->overheadRateOn(Carbon::instance($this->end));
                $budget = $rate?->budgeted_machine_hours !== null ? (float) $rate->budgeted_machine_hours : null;

                return [
                    'name' => $center->name,
                    'actual' => $hours,
                    'budget' => $budget,
                    'utilisation' => $budget ? $hours / $budget * 100 : null,
                ];
            });
    }

    /**
     * @return array{overdue: Collection<int, WorkOrder>, awaiting_result: int, draft_results: int, awaiting_receipt: int, awaiting_return: int}
     */
    public static function attention(CarbonInterface $today): array
    {
        $open = [WorkOrderStatus::Released->value, WorkOrderStatus::InProgress->value];

        return [
            'overdue' => WorkOrder::query()
                ->whereIn('status', $open)
                ->whereDate('planned_end_date', '<', $today->toDateString())
                ->with('product')
                ->orderBy('planned_end_date')
                ->get(),
            'awaiting_result' => WorkOrder::query()
                ->where('status', WorkOrderStatus::InProgress->value)
                ->whereDoesntHave('productionResult')
                ->count(),
            'draft_results' => ProductionResult::where('status', DocumentStatus::Draft->value)->count(),
            'awaiting_receipt' => ProductionResult::query()
                ->where('status', DocumentStatus::Posted->value)
                ->whereDoesntHave('productionReceipts', fn (Builder $q) => $q->where('status', DocumentStatus::Posted->value))
                ->count(),
            'awaiting_return' => ProductionResult::query()
                ->where('status', DocumentStatus::Posted->value)
                ->whereDoesntHave('materialReturns', fn (Builder $q) => $q->where('status', DocumentStatus::Posted->value))
                ->with('workOrder')
                ->get()
                ->filter(fn (ProductionResult $result) => $result->workOrder->postedIssueItems()
                    ->contains(fn ($line) => bccomp((string) $line->quantity, (string) $line->quantity_consumed, 2) > 0))
                ->count(),
        ];
    }

    /**
     * @return Collection<int, array{sales_order: SalesOrder, items: Collection<int, array{product_id: int, product_name: string, ordered: string, extra_available: int}>}>
     */
    public static function draftSalesOpportunities(): Collection
    {
        $draftOrders = SalesOrder::query()
            ->where('status', SalesOrderStatus::Draft->value)
            ->with(['customer', 'items.product'])
            ->orderBy('order_date')
            ->get();

        $productIds = $draftOrders->flatMap(fn (SalesOrder $order) => $order->items->pluck('product_id'))->unique();
        if ($productIds->isEmpty()) {
            return collect();
        }

        $formulas = ProductionFormula::query()
            ->where('is_active', true)
            ->whereIn('product_id', $productIds)
            ->with('items.materialProduct')
            ->get()
            ->keyBy('product_id');

        return $draftOrders
            ->map(function (SalesOrder $order) use ($formulas) {
                $items = $order->items
                    ->map(function ($item) use ($formulas) {
                        $formula = $formulas->get($item->product_id);
                        $extra = $formula ? self::producibleUnits($formula) : 0;

                        return $extra > 0 ? [
                            'product_id' => $item->product_id,
                            'product_name' => $item->product->product_name,
                            'ordered' => (string) $item->quantity_ordered,
                            'extra_available' => $extra,
                        ] : null;
                    })
                    ->filter()
                    ->values();

                return ['sales_order' => $order, 'items' => $items];
            })
            ->filter(fn (array $row) => $row['items']->isNotEmpty())
            ->values();
    }

    protected static function producibleUnits(ProductionFormula $formula): int
    {
        if ($formula->items->isEmpty() || bccomp((string) $formula->output_quantity, '0', 2) <= 0) {
            return 0;
        }

        $max = null;
        foreach ($formula->items as $item) {
            if (bccomp((string) $item->quantity, '0', 2) <= 0) {
                continue;
            }
            $unitsFromThisMaterial = bcdiv(bcmul((string) $item->materialProduct->current_stock, (string) $formula->output_quantity, 6), (string) $item->quantity, 0);
            $max = $max === null ? $unitsFromThisMaterial : (bccomp($unitsFromThisMaterial, $max, 0) < 0 ? $unitsFromThisMaterial : $max);
        }

        return (int) ($max ?? '0');
    }

    /**
     * @return list<self>
     */
    protected function trailingMonths(int $months): array
    {
        $periods = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = $this->start->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $periods[] = $this->forPeriod($start, $start->copy()->endOfMonth());
        }

        return $periods;
    }

    protected function postedResults(): Builder
    {
        return ProductionResult::query()
            ->where('production_results.status', DocumentStatus::Posted->value)
            ->whereBetween('production_date', [$this->start->toDateString(), $this->end->toDateString()])
            ->when($this->type, fn (Builder $q) => $q->whereHas('workOrder.product', fn (Builder $q) => $q->where('type', $this->type->value)));
    }

    protected function postedInPeriod(Builder $query): Builder
    {
        return $query
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('received_at', [$this->start->copy()->startOfDay(), $this->end->copy()->endOfDay()]);
    }
}
