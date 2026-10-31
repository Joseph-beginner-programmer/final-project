<?php

namespace App\Support\Reports;

use App\Enums\AccountType;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CashFlowReport
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    /**
     * @return array{cash_accounts: Collection, opening_cash: string, closing_cash: string, net_change: string, operating: Collection, financing: Collection, totals: array{operating: string, financing: string}, reconciled: bool}
     */
    public function build(): array
    {
        $cashAccounts = Account::where('is_cash', true)->orderBy('account_code')->get();
        $cashAccountIds = $cashAccounts->pluck('id');

        $openingNet = AccountBalances::net(null, $this->start->copy()->subDay());
        $closingNet = AccountBalances::net(null, $this->end);
        $openingCash = $cashAccounts->reduce(fn (string $carry, Account $a) => bcadd($carry, $openingNet[$a->id] ?? '0', 2), '0');
        $closingCash = $cashAccounts->reduce(fn (string $carry, Account $a) => bcadd($carry, $closingNet[$a->id] ?? '0', 2), '0');

        $movements = $this->movements($cashAccountIds);
        $financing = $movements->filter(fn (array $row) => $row['account']->type === AccountType::Equity)->values();
        $operating = $movements->reject(fn (array $row) => $row['account']->type === AccountType::Equity)->values();

        $totals = [
            'operating' => AccountBalances::sum($operating, 'amount'),
            'financing' => AccountBalances::sum($financing, 'amount'),
        ];

        $netChange = bcsub($closingCash, $openingCash, 2);
        $fromMovements = bcadd($totals['operating'], $totals['financing'], 2);

        return [
            'cash_accounts' => $cashAccounts,
            'opening_cash' => $openingCash,
            'closing_cash' => $closingCash,
            'net_change' => $netChange,
            'operating' => $operating,
            'financing' => $financing,
            'totals' => $totals,
            'reconciled' => bccomp($netChange, $fromMovements, 2) === 0,
        ];
    }

    /**
     * @param  Collection<int, int>  $cashAccountIds
     * @return Collection<int, array{account: Account, amount: string}>
     */
    protected function movements(Collection $cashAccountIds): Collection
    {
        if ($cashAccountIds->isEmpty()) {
            return collect();
        }

        $entryIds = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $cashAccountIds)
            ->whereBetween('journal_entries.entry_date', [$this->start->toDateString(), $this->end->toDateString()])
            ->pluck('journal_entries.id')
            ->unique();

        if ($entryIds->isEmpty()) {
            return collect();
        }

        $byAccount = DB::table('journal_entry_lines')
            ->whereIn('journal_entry_id', $entryIds)
            ->whereNotIn('account_id', $cashAccountIds)
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(credit) - SUM(debit) AS effect')
            ->pluck('effect', 'account_id');

        return Account::whereIn('id', $byAccount->keys())
            ->orderBy('account_code')
            ->get()
            ->map(fn (Account $account) => ['account' => $account, 'amount' => bcadd((string) $byAccount[$account->id], '0', 2)])
            ->filter(fn (array $row) => bccomp($row['amount'], '0', 2) !== 0)
            ->sortBy(fn (array $row) => $row['account']->account_code)
            ->values();
    }

    /**
     * @param  array{cash_accounts: Collection, opening_cash: string, closing_cash: string, net_change: string, operating: Collection, financing: Collection, totals: array<string, string>, reconciled: bool}  $data
     * @return list<list<string>>
     */
    public static function excel(array $data): array
    {
        $rows = [];
        $rows[] = [__('Opening cash'), Excel::number($data['opening_cash'])];
        $rows[] = ['', ''];

        foreach ([
            ['operating', __('Operating activities')],
            ['financing', __('Financing activities')],
        ] as [$key, $title]) {
            $rows[] = [$title, ''];
            foreach ($data[$key] as $line) {
                $rows[] = [$line['account']->account_code.' '.$line['account']->account_name, Excel::number($line['amount'])];
            }
            if ($data[$key]->isEmpty()) {
                $rows[] = [__('None'), Excel::number('0')];
            }
            $rows[] = ['', Excel::number($data['totals'][$key])];
            $rows[] = ['', ''];
        }

        $rows[] = [__('Net change in cash'), Excel::number($data['net_change'])];
        $rows[] = [__('Closing cash'), Excel::number($data['closing_cash'])];

        return $rows;
    }
}
