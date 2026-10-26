<?php

namespace App\Support\Kpi;

use App\Enums\AccountSystemRole;
use App\Enums\DocumentStatus;
use App\Enums\ProductType;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesInvoiceStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Account;
use App\Models\ProductionResult;
use App\Models\PurchaseOrder;
use App\Models\WorkOrder;
use App\Support\MaterialShortage;
use App\Support\OverheadPeriod;
use App\Support\Reports\AccountBalances;
use App\Support\Reports\IncomeStatementReport;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExecutiveKpis
{
    public const TREND_MONTHS = 6;

    public const MAX_CUSTOMER_SEGMENTS = 5;

    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $cache = [];

    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    public function asOf(): Carbon
    {
        return $this->end->lt(today()) ? $this->end->copy() : today();
    }

    /**
     * @return list<array{start: Carbon, end: Carbon, label: string}>
     */
    public function months(): array
    {
        return $this->cache['months'] ??= collect(range(self::TREND_MONTHS - 1, 0))
            ->map(function (int $back) {
                $start = $this->start->copy()->startOfMonth()->subMonthsNoOverflow($back);

                return ['start' => $start, 'end' => $start->copy()->endOfMonth(), 'label' => $start->translatedFormat('M y')];
            })
            ->all();
    }

    /**
     * @return array{labels: list<string>, revenue: list<float>, cost_of_sales: list<float>, gross_profit: list<float>, net_profit: list<float>, margin: list<?float>}
     */
    public function profitTrend(): array
    {
        return $this->cache['profit'] ??= (function () {
            $trend = ['labels' => [], 'revenue' => [], 'cost_of_sales' => [], 'gross_profit' => [], 'net_profit' => [], 'margin' => []];

            foreach ($this->months() as $month) {
                $totals = (new IncomeStatementReport($month['start'], $month['end']))->build()['totals'];
                $revenue = (float) $totals['revenue']['month'];
                $gross = (float) $totals['gross_profit']['month'];

                $trend['labels'][] = $month['label'];
                $trend['revenue'][] = $revenue;
                $trend['cost_of_sales'][] = (float) $totals['cost_of_sales']['month'];
                $trend['gross_profit'][] = $gross;
                $trend['net_profit'][] = (float) $totals['net_profit']['month'];
                $trend['margin'][] = $revenue > 0 ? $gross / $revenue * 100 : null;
            }

            return $trend;
        })();
    }

    /**
     * @return array{revenue: Collection, cost_of_sales: Collection, expenses: Collection, totals: array<string, array{month: string, ytd: string}>}
     */
    public function incomeStatement(): array
    {
        return $this->cache['income'] ??= (new IncomeStatementReport($this->start, $this->end))->build();
    }

    /**
     * @return list<int>
     */
    public function cashAccountIds(): array
    {
        return $this->cache['cash_ids'] ??= DB::table('sales_payments')->distinct()->pluck('cash_account_id')
            ->merge(DB::table('purchase_payments')->distinct()->pluck('cash_account_id'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{labels: list<string>, cash: list<float>, inventory: list<float>, inventory_by_type: list<array<string, float>>}
     */
    public function balanceTrend(): array
    {
        return $this->cache['balances'] ??= (function () {
            $inventoryRoles = $this->inventoryRoles();
            $roleAccounts = Account::whereIn('system_role', array_map(fn (AccountSystemRole $role) => $role->value, array_merge(...array_values($inventoryRoles))))
                ->pluck('id', 'system_role');
            $cashIds = $this->cashAccountIds();

            $trend = ['labels' => [], 'cash' => [], 'inventory' => [], 'inventory_by_type' => []];
            foreach ($this->months() as $month) {
                $net = AccountBalances::net(null, $month['end']);

                $byType = [];
                foreach ($inventoryRoles as $key => $roles) {
                    $byType[$key] = array_sum(array_map(fn (AccountSystemRole $role) => (float) ($net[$roleAccounts[$role->value] ?? 0] ?? 0), $roles));
                }

                $trend['labels'][] = $month['label'];
                $trend['cash'][] = array_sum(array_map(fn (int $id) => (float) ($net[$id] ?? 0), $cashIds));
                $trend['inventory'][] = array_sum($byType);
                $trend['inventory_by_type'][] = $byType;
            }

            return $trend;
        })();
    }

    /**
     * @return array<string, list<AccountSystemRole>>
     */
    public function inventoryRoles(): array
    {
        return [
            ProductType::RawMaterial->value => [AccountSystemRole::InventoryRaw],
            'in_process' => [AccountSystemRole::InventoryWip],
            ProductType::Wip->value => [AccountSystemRole::InventorySemiFinished],
            ProductType::FinishedGoods->value => [AccountSystemRole::InventoryFinished],
        ];
    }

    public function overheadOpen(): bool
    {
        $period = new OverheadPeriod($this->start->format('Y-m'));

        return ! $period->isClosed() && ($this->overheadTrend()['applied'][self::TREND_MONTHS - 1] ?? 0) != 0;
    }

    /**
     * @return array{labels: list<string>, applied: list<float>, actual: list<float>}
     */
    public function overheadTrend(): array
    {
        return $this->cache['overhead'] ??= $this->buildOverheadTrend();
    }

    /**
     * @return array{labels: list<string>, applied: list<float>, actual: list<float>}
     */
    protected function buildOverheadTrend(): array
    {
        $months = $this->months();
        $actualRoles = [
            AccountSystemRole::OverheadElectricity->value,
            AccountSystemRole::OverheadMachineDepreciation->value,
            AccountSystemRole::OverheadMoldDepreciation->value,
            AccountSystemRole::OverheadMaintenance->value,
            AccountSystemRole::OverheadOther->value,
        ];

        $rows = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->whereIn('accounts.system_role', [...$actualRoles, AccountSystemRole::OverheadApplied->value])
            ->whereDate('journal_entries.entry_date', '>=', $months[0]['start']->toDateString())
            ->whereDate('journal_entries.entry_date', '<=', $months[count($months) - 1]['end']->toDateString())
            ->where(fn ($q) => $q->whereNull('journal_entries.source_type')->orWhere('journal_entries.source_type', '!=', OverheadPeriod::CLOSING_SOURCE))
            ->groupByRaw("DATE_FORMAT(journal_entries.entry_date, '%Y-%m'), accounts.system_role")
            ->selectRaw("DATE_FORMAT(journal_entries.entry_date, '%Y-%m') as month, accounts.system_role as role, SUM(journal_entry_lines.debit) as debit, SUM(journal_entry_lines.credit) as credit")
            ->get();

        $trend = ['labels' => [], 'applied' => [], 'actual' => []];
        foreach ($months as $month) {
            $inMonth = $rows->where('month', $month['start']->format('Y-m'));
            $applied = $inMonth->where('role', AccountSystemRole::OverheadApplied->value)->sum(fn ($row) => (float) $row->credit - (float) $row->debit);
            $actual = $inMonth->whereIn('role', $actualRoles)->sum(fn ($row) => (float) $row->debit - (float) $row->credit);

            $trend['labels'][] = $month['label'];
            $trend['applied'][] = round($applied, 2);
            $trend['actual'][] = round($actual, 2);
        }

        return $trend;
    }

    /**
     * @return Collection<int, array{product: string, quantity: float, revenue: float, cost: float, profit: float, margin: ?float}>
     */
    public function productMargins(): Collection
    {
        return DB::table('sales_delivery_items')
            ->join('sales_deliveries', 'sales_deliveries.id', '=', 'sales_delivery_items.sales_delivery_id')
            ->join('sales_order_items', 'sales_order_items.id', '=', 'sales_delivery_items.sales_order_item_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_deliveries.sales_order_id')
            ->join('products', 'products.id', '=', 'sales_delivery_items.product_id')
            ->where('sales_deliveries.status', DocumentStatus::Posted->value)
            ->whereBetween('sales_deliveries.delivered_at', [$this->start->copy()->startOfDay(), $this->end->copy()->endOfDay()])
            ->groupBy('products.id', 'products.product_name')
            ->selectRaw('products.product_name as product, SUM(sales_delivery_items.quantity) as quantity, SUM(sales_delivery_items.quantity * sales_order_items.unit_price * (1 - sales_orders.discount_percent / 100)) as revenue, SUM(sales_delivery_items.cogs_total) as cost')
            ->get()
            ->map(function ($row) {
                $revenue = round((float) $row->revenue, 2);
                $cost = round((float) $row->cost, 2);

                return [
                    'product' => $row->product,
                    'quantity' => (float) $row->quantity,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $revenue - $cost,
                    'margin' => $revenue > 0 ? ($revenue - $cost) / $revenue * 100 : null,
                ];
            })
            ->sortByDesc('profit')
            ->values();
    }

    /**
     * @return array{segments: list<array{id: string, name: string, value: float, slot: int}>, total: float}
     */
    public function customerShare(): array
    {
        $rows = DB::table('sales_invoices')
            ->join('customers', 'customers.id', '=', 'sales_invoices.customer_id')
            ->whereIn('sales_invoices.status', $this->postedSalesStatuses())
            ->whereBetween('sales_invoices.invoice_date', [$this->start->toDateString(), $this->end->toDateString()])
            ->groupBy('customers.id', 'customers.customer_name')
            ->selectRaw('customers.id, customers.customer_name as name, SUM(sales_invoices.subtotal) as value')
            ->orderByDesc('value')
            ->get();

        $segments = $rows->take(self::MAX_CUSTOMER_SEGMENTS)->values()
            ->map(fn ($row, int $i) => ['id' => 'c'.$row->id, 'name' => $row->name, 'value' => (float) $row->value, 'slot' => $i + 1])
            ->all();

        $rest = $rows->slice(self::MAX_CUSTOMER_SEGMENTS)->sum(fn ($row) => (float) $row->value);
        if ($rest > 0) {
            $segments[] = ['id' => 'other', 'name' => __('Others'), 'value' => $rest, 'slot' => self::MAX_CUSTOMER_SEGMENTS + 1];
        }

        return ['segments' => $segments, 'total' => (float) $rows->sum(fn ($row) => (float) $row->value)];
    }

    /**
     * @return array{buckets: list<array{key: string, amount: float, count: int}>, total: float, overdue: float, overdue_count: int, top: Collection<int, array{name: string, amount: float, days: int}>}
     */
    public function receivablesAging(): array
    {
        return $this->aging('sales_invoices', 'sales_payments', 'sales_invoice_id', 'customers', 'customer_id', 'customer_name', $this->postedSalesStatuses());
    }

    /**
     * @return array{buckets: list<array{key: string, amount: float, count: int}>, total: float, overdue: float, overdue_count: int, top: Collection<int, array{name: string, amount: float, days: int}>}
     */
    public function payablesAging(): array
    {
        return $this->aging('purchase_invoices', 'purchase_payments', 'purchase_invoice_id', 'suppliers', 'supplier_id', 'supplier_name', [
            PurchaseInvoiceStatus::Unpaid->value, PurchaseInvoiceStatus::PartiallyPaid->value, PurchaseInvoiceStatus::Paid->value,
        ]);
    }

    /**
     * @return list<string>
     */
    public static function agingKeys(): array
    {
        return ['current', '1_30', '31_60', '61_90', 'over_90'];
    }

    /**
     * @param  list<string>  $statuses
     * @return array{buckets: list<array{key: string, amount: float, count: int}>, total: float, overdue: float, overdue_count: int, top: Collection<int, array{name: string, amount: float, days: int}>}
     */
    protected function aging(string $invoices, string $payments, string $paymentKey, string $parties, string $partyKey, string $partyName, array $statuses): array
    {
        $asOf = $this->asOf();

        $paid = DB::table($payments)
            ->where('status', DocumentStatus::Posted->value)
            ->whereDate('payment_date', '<=', $asOf->toDateString())
            ->groupBy($paymentKey)
            ->selectRaw("$paymentKey as invoice_id, SUM(amount) as paid")
            ->pluck('paid', 'invoice_id');

        $open = DB::table($invoices)
            ->join($parties, "$parties.id", '=', "$invoices.$partyKey")
            ->whereIn("$invoices.status", $statuses)
            ->whereDate("$invoices.invoice_date", '<=', $asOf->toDateString())
            ->get(["$invoices.id", "$invoices.total_amount", "$invoices.due_date", "$parties.$partyName as party"])
            ->map(function ($row) use ($paid, $asOf) {
                $balance = round((float) $row->total_amount - (float) ($paid[$row->id] ?? 0), 2);
                $days = (int) Carbon::parse($row->due_date)->startOfDay()->diffInDays($asOf->copy()->startOfDay(), false);

                return ['party' => $row->party, 'balance' => $balance, 'days' => $days, 'bucket' => self::bucketFor($days)];
            })
            ->filter(fn (array $row) => $row['balance'] > 0.004);

        $buckets = array_map(fn (string $key) => [
            'key' => $key,
            'amount' => round($open->where('bucket', $key)->sum('balance'), 2),
            'count' => $open->where('bucket', $key)->count(),
        ], self::agingKeys());

        $overdue = $open->where('days', '>', 0);

        return [
            'buckets' => $buckets,
            'total' => round($open->sum('balance'), 2),
            'overdue' => round($overdue->sum('balance'), 2),
            'overdue_count' => $overdue->count(),
            'top' => $overdue->groupBy('party')
                ->map(fn (Collection $rows, string $party) => ['name' => $party, 'amount' => round($rows->sum('balance'), 2), 'days' => $rows->max('days')])
                ->sortByDesc('amount')
                ->take(3)
                ->values(),
        ];
    }

    public static function bucketFor(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => '1_30',
            $daysOverdue <= 60 => '31_60',
            $daysOverdue <= 90 => '61_90',
            default => 'over_90',
        };
    }

    /**
     * @return array{labels: list<string>, series: list<array{id: string, values: list<float>}>, total: float, old_share: ?float}
     */
    public function stockAge(): array
    {
        $today = today();
        $keys = ['0_30', '31_60', '61_90', 'over_90'];
        $types = [ProductType::RawMaterial->value, ProductType::Wip->value, ProductType::FinishedGoods->value];

        $rows = DB::table('inventory_lots')
            ->join('products', 'products.id', '=', 'inventory_lots.product_id')
            ->where('inventory_lots.quantity_remaining', '>', 0)
            ->get(['products.type', 'inventory_lots.value_remaining', 'inventory_lots.received_at'])
            ->map(function ($row) use ($today) {
                $days = (int) Carbon::parse($row->received_at)->startOfDay()->diffInDays($today, false);

                return [
                    'type' => $row->type,
                    'value' => (float) $row->value_remaining,
                    'bucket' => match (true) { $days <= 30 => '0_30', $days <= 60 => '31_60', $days <= 90 => '61_90', default => 'over_90' },
                ];
            });

        $total = $rows->sum('value');

        return [
            'labels' => $keys,
            'series' => array_map(fn (string $type) => [
                'id' => $type,
                'values' => array_map(fn (string $key) => round($rows->where('type', $type)->where('bucket', $key)->sum('value'), 2), $keys),
            ], $types),
            'total' => round($total, 2),
            'old_share' => $total > 0 ? $rows->whereIn('bucket', ['61_90', 'over_90'])->sum('value') / $total * 100 : null,
        ];
    }

    /**
     * @return array{draft: int, released: int, in_progress: int, completed: int}
     */
    public function workOrderPipeline(): array
    {
        $open = WorkOrder::query()
            ->whereIn('status', [WorkOrderStatus::Draft->value, WorkOrderStatus::Released->value, WorkOrderStatus::InProgress->value])
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as total')
            ->pluck('total', 'status');

        return [
            'draft' => (int) ($open[WorkOrderStatus::Draft->value] ?? 0),
            'released' => (int) ($open[WorkOrderStatus::Released->value] ?? 0),
            'in_progress' => (int) ($open[WorkOrderStatus::InProgress->value] ?? 0),
            'completed' => ProductionResult::query()
                ->where('status', DocumentStatus::Posted->value)
                ->whereBetween('production_date', [$this->start->toDateString(), $this->end->toDateString()])
                ->count(),
        ];
    }

    /**
     * @return array{completed: int, over: int, overrun: float, planned: float, actual: float}
     */
    public function costVsPlan(): array
    {
        $rows = (new ProductionKpis($this->start, $this->end))->completedWorkOrders();

        return [
            'completed' => $rows->count(),
            'over' => $rows->where('over_plan', true)->count(),
            'overrun' => round($rows->sum(fn (array $row) => max(0, (float) $row['cost_difference'])), 2),
            'planned' => round($rows->sum(fn (array $row) => (float) $row['planned_cost']), 2),
            'actual' => round($rows->sum(fn (array $row) => (float) $row['actual_cost']), 2),
        ];
    }

    /**
     * @return array{pending_po: int, overdue_receivables: array{count: int, amount: float}, payables_due: array{count: int, amount: float}, shortages: int, late_work_orders: int, unclosed_overhead: ?string, unmapped_roles: int}
     */
    public static function attention(Carbon $today): array
    {
        $receivables = DB::table('sales_invoices')
            ->whereIn('status', [SalesInvoiceStatus::Unpaid->value, SalesInvoiceStatus::PartiallyPaid->value])
            ->whereDate('due_date', '<', $today->toDateString())
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(total_amount - amount_paid), 0) as amount')
            ->first();

        $payables = DB::table('purchase_invoices')
            ->whereIn('status', [PurchaseInvoiceStatus::Unpaid->value, PurchaseInvoiceStatus::PartiallyPaid->value])
            ->whereDate('due_date', '<=', $today->copy()->addDays(7)->toDateString())
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(total_amount - amount_paid), 0) as amount')
            ->first();

        $lastMonth = OverheadPeriod::of($today->copy()->subMonthNoOverflow());
        $appliedLastMonth = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('accounts.system_role', AccountSystemRole::OverheadApplied->value)
            ->whereBetween('journal_entries.entry_date', [$lastMonth->start->toDateString(), $lastMonth->end->toDateString()])
            ->exists();

        $mapped = Account::whereNotNull('system_role')->pluck('system_role')->map(fn ($role) => $role instanceof AccountSystemRole ? $role->value : $role)->all();

        return [
            'pending_po' => PurchaseOrder::where('status', PurchaseOrderStatus::PendingApproval->value)->count(),
            'overdue_receivables' => ['count' => (int) $receivables->total, 'amount' => (float) $receivables->amount],
            'payables_due' => ['count' => (int) $payables->total, 'amount' => (float) $payables->amount],
            'shortages' => MaterialShortage::suggestions()->count(),
            'late_work_orders' => ProductionKpis::attention($today)['overdue']->count(),
            'unclosed_overhead' => $appliedLastMonth && ! $lastMonth->isClosed() ? $lastMonth->period : null,
            'unmapped_roles' => count(array_diff(array_map(fn (AccountSystemRole $role) => $role->value, AccountSystemRole::cases()), $mapped)),
        ];
    }

    /**
     * @return list<string>
     */
    protected function postedSalesStatuses(): array
    {
        return [SalesInvoiceStatus::Unpaid->value, SalesInvoiceStatus::PartiallyPaid->value, SalesInvoiceStatus::Paid->value];
    }
}
