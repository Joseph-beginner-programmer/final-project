<?php

namespace App\Support\Reports;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrialBalanceReport
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    /**
     * @return Collection<int, array{account: Account, opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, abnormal: bool}>
     */
    public function rows(bool $includeEmpty = false): Collection
    {
        $sums = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereDate('journal_entries.entry_date', '<=', $this->end->toDateString())
            ->groupBy('journal_entry_lines.account_id')
            ->selectRaw('journal_entry_lines.account_id,
                SUM(CASE WHEN journal_entries.entry_date < ? THEN journal_entry_lines.debit - journal_entry_lines.credit ELSE 0 END) AS opening_net,
                SUM(CASE WHEN journal_entries.entry_date >= ? THEN journal_entry_lines.debit ELSE 0 END) AS period_debit,
                SUM(CASE WHEN journal_entries.entry_date >= ? THEN journal_entry_lines.credit ELSE 0 END) AS period_credit',
                [$this->start->toDateString(), $this->start->toDateString(), $this->start->toDateString()])
            ->get()
            ->keyBy('account_id');

        $order = array_flip(array_map(fn (AccountType $type) => $type->value, AccountType::cases()));

        return Account::query()
            ->orderBy('account_code')
            ->get()
            ->map(function (Account $account) use ($sums) {
                $sum = $sums->get($account->id);
                $opening = bcadd((string) ($sum->opening_net ?? '0'), '0', 2);
                $debit = bcadd((string) ($sum->period_debit ?? '0'), '0', 2);
                $credit = bcadd((string) ($sum->period_credit ?? '0'), '0', 2);
                $closing = bcadd($opening, bcsub($debit, $credit, 2), 2);
                [$openingDebit, $openingCredit] = self::split($opening);
                [$closingDebit, $closingCredit] = self::split($closing);

                return [
                    'account' => $account,
                    'opening_debit' => $openingDebit,
                    'opening_credit' => $openingCredit,
                    'debit' => $debit,
                    'credit' => $credit,
                    'closing_debit' => $closingDebit,
                    'closing_credit' => $closingCredit,
                    'abnormal' => bccomp($closing, '0', 2) !== 0 && self::sideOf($closing) !== $account->normal_balance,
                ];
            })
            ->filter(fn (array $row) => $includeEmpty
                ? $row['account']->is_active || self::hasAmounts($row)
                : self::hasAmounts($row))
            ->sortBy(fn (array $row) => sprintf('%d-%s', $order[$row['account']->type->value], $row['account']->account_code))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string}
     */
    public static function totals(Collection $rows): array
    {
        $keys = ['opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'];

        return array_combine($keys, array_map(
            fn (string $key) => $rows->reduce(fn (string $carry, array $row) => bcadd($carry, $row[$key], 2), '0'),
            $keys,
        ));
    }

    /**
     * @param  array{opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string}  $totals
     */
    public static function isBalanced(array $totals): bool
    {
        return bccomp($totals['opening_debit'], $totals['opening_credit'], 2) === 0
            && bccomp($totals['debit'], $totals['credit'], 2) === 0
            && bccomp($totals['closing_debit'], $totals['closing_credit'], 2) === 0;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function split(string $net): array
    {
        return bccomp($net, '0', 2) >= 0 ? [$net, '0'] : ['0', bcmul($net, '-1', 2)];
    }

    public static function sideOf(string $net): NormalBalance
    {
        return bccomp($net, '0', 2) >= 0 ? NormalBalance::Debit : NormalBalance::Credit;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function hasAmounts(array $row): bool
    {
        foreach (['opening_debit', 'opening_credit', 'debit', 'credit'] as $key) {
            if (bccomp($row[$key], '0', 2) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<list<string>>
     */
    public function excel(Collection $rows): array
    {
        return $rows->map(fn (array $row) => [
            $row['account']->account_code,
            $row['account']->account_name,
            $row['account']->type->label(),
            Excel::number($row['opening_debit']),
            Excel::number($row['opening_credit']),
            Excel::number($row['debit']),
            Excel::number($row['credit']),
            Excel::number($row['closing_debit']),
            Excel::number($row['closing_credit']),
        ])->all();
    }
}
