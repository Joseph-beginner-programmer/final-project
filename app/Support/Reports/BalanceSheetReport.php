<?php

namespace App\Support\Reports;

use App\Enums\AccountType;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BalanceSheetReport
{
    public function __construct(
        public readonly Carbon $asOf,
    ) {}

    /**
     * @return array{assets: Collection, liabilities: Collection, equity: Collection, profit: string, totals: array{assets: string, liabilities: string, equity: string, liabilities_and_equity: string}, balanced: bool}
     */
    public function build(): array
    {
        $net = AccountBalances::net(null, $this->asOf);

        $lines = Account::query()
            ->whereIn('type', [AccountType::Asset->value, AccountType::Liability->value, AccountType::Equity->value])
            ->orderBy('account_code')
            ->get()
            ->map(fn (Account $account) => ['account' => $account, 'amount' => AccountBalances::natural($account, $net)])
            ->filter(fn (array $line) => bccomp($line['amount'], '0', 2) !== 0);

        $assets = $lines->filter(fn (array $line) => $line['account']->type === AccountType::Asset)->values();
        $liabilities = $lines->filter(fn (array $line) => $line['account']->type === AccountType::Liability)->values();
        $equity = $lines->filter(fn (array $line) => $line['account']->type === AccountType::Equity)->values();

        $profit = Account::query()
            ->whereIn('type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->get()
            ->reduce(fn (string $carry, Account $account) => bcsub($carry, $net[$account->id] ?? '0', 2), '0.00');

        $totalAssets = AccountBalances::sum($assets, 'amount');
        $totalLiabilities = AccountBalances::sum($liabilities, 'amount');
        $totalEquity = bcadd(AccountBalances::sum($equity, 'amount'), $profit, 2);
        $right = bcadd($totalLiabilities, $totalEquity, 2);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'profit' => $profit,
            'totals' => [
                'assets' => $totalAssets,
                'liabilities' => $totalLiabilities,
                'equity' => $totalEquity,
                'liabilities_and_equity' => $right,
            ],
            'balanced' => bccomp($totalAssets, $right, 2) === 0,
        ];
    }

    /**
     * @param  array{assets: Collection, liabilities: Collection, equity: Collection, profit: string, totals: array<string, string>, balanced: bool}  $data
     * @return list<list<string>>
     */
    public static function excel(array $data): array
    {
        $rows = [];
        foreach ([['assets', __('Assets'), __('Total assets')], ['liabilities', __('Liabilities'), __('Total liabilities')]] as [$key, $title, $total]) {
            $rows[] = [$title, '', ''];
            foreach ($data[$key] as $line) {
                $rows[] = [$line['account']->account_code, $line['account']->account_name, Excel::number($line['amount'])];
            }
            $rows[] = ['', $total, Excel::number($data['totals'][$key])];
        }
        $rows[] = [__('Equity'), '', ''];
        foreach ($data['equity'] as $line) {
            $rows[] = [$line['account']->account_code, $line['account']->account_name, Excel::number($line['amount'])];
        }
        $rows[] = ['', __('Current earnings (not yet closed)'), Excel::number($data['profit'])];
        $rows[] = ['', __('Total equity'), Excel::number($data['totals']['equity'])];
        $rows[] = ['', __('Total liabilities and equity'), Excel::number($data['totals']['liabilities_and_equity'])];

        return $rows;
    }
}
