<?php

namespace App\Support\Reports;

use App\Enums\AccountSystemRole;
use App\Enums\AccountType;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class IncomeStatementReport
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    /**
     * @return list<AccountSystemRole>
     */
    public static function costOfSalesRoles(): array
    {
        return [
            AccountSystemRole::Cogs,
            AccountSystemRole::MaterialVariance,
            AccountSystemRole::ProductionLoss,
            AccountSystemRole::PurchasePriceVariance,
            AccountSystemRole::OverheadElectricity,
            AccountSystemRole::OverheadMachineDepreciation,
            AccountSystemRole::OverheadMoldDepreciation,
            AccountSystemRole::OverheadMaintenance,
            AccountSystemRole::OverheadOther,
            AccountSystemRole::OverheadApplied,
        ];
    }

    public function yearStart(): Carbon
    {
        return $this->end->copy()->startOfYear();
    }

    /**
     * @return array{revenue: Collection, cost_of_sales: Collection, expenses: Collection, totals: array<string, array{month: string, ytd: string}>}
     */
    public function build(): array
    {
        $month = AccountBalances::net($this->start, $this->end);
        $ytd = AccountBalances::net($this->yearStart(), $this->end);
        $costRoles = self::costOfSalesRoles();

        $lines = Account::query()
            ->whereIn('type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->orderBy('account_code')
            ->get()
            ->map(fn (Account $account) => [
                'account' => $account,
                'month' => AccountBalances::natural($account, $month),
                'ytd' => AccountBalances::natural($account, $ytd),
            ])
            ->filter(fn (array $line) => bccomp($line['month'], '0', 2) !== 0 || bccomp($line['ytd'], '0', 2) !== 0);

        $revenue = $lines->filter(fn (array $line) => $line['account']->type === AccountType::Revenue)->values();
        $expenseLines = $lines->filter(fn (array $line) => $line['account']->type === AccountType::Expense);
        $costOfSales = $expenseLines->filter(fn (array $line) => in_array($line['account']->system_role, $costRoles, true))
            ->sortBy(fn (array $line) => array_search($line['account']->system_role, $costRoles, true))
            ->values();
        $expenses = $expenseLines->reject(fn (array $line) => in_array($line['account']->system_role, $costRoles, true))->values();

        $totals = [];
        foreach (['month', 'ytd'] as $column) {
            $totalRevenue = AccountBalances::sum($revenue, $column);
            $totalCost = AccountBalances::sum($costOfSales, $column);
            $gross = bcsub($totalRevenue, $totalCost, 2);
            $totalExpenses = AccountBalances::sum($expenses, $column);
            $totals['revenue'][$column] = $totalRevenue;
            $totals['cost_of_sales'][$column] = $totalCost;
            $totals['gross_profit'][$column] = $gross;
            $totals['expenses'][$column] = $totalExpenses;
            $totals['net_profit'][$column] = bcsub($gross, $totalExpenses, 2);
        }

        return ['revenue' => $revenue, 'cost_of_sales' => $costOfSales, 'expenses' => $expenses, 'totals' => $totals];
    }

    public static function margin(string $profit, string $revenue): ?float
    {
        return bccomp($revenue, '0', 2) > 0 ? (float) bcdiv(bcmul($profit, '100', 4), $revenue, 4) : null;
    }

    /**
     * @param  array{revenue: Collection, cost_of_sales: Collection, expenses: Collection, totals: array<string, array{month: string, ytd: string}>}  $data
     * @return list<list<string>>
     */
    public static function excel(array $data): array
    {
        $rows = [];
        $section = function (string $title, Collection $lines, string $totalLabel, array $total) use (&$rows) {
            $rows[] = [$title, '', '', ''];
            foreach ($lines as $line) {
                $rows[] = [$line['account']->account_code, $line['account']->account_name, Excel::number($line['month']), Excel::number($line['ytd'])];
            }
            $rows[] = ['', $totalLabel, Excel::number($total['month']), Excel::number($total['ytd'])];
        };
        $section(__('Revenue'), $data['revenue'], __('Total revenue'), $data['totals']['revenue']);
        $section(__('Cost of goods sold'), $data['cost_of_sales'], __('Total cost of goods sold'), $data['totals']['cost_of_sales']);
        $rows[] = ['', __('Gross profit'), Excel::number($data['totals']['gross_profit']['month']), Excel::number($data['totals']['gross_profit']['ytd'])];
        $section(__('Operating expenses'), $data['expenses'], __('Total operating expenses'), $data['totals']['expenses']);
        $rows[] = ['', __('Net profit (loss)'), Excel::number($data['totals']['net_profit']['month']), Excel::number($data['totals']['net_profit']['ytd'])];

        return $rows;
    }
}
