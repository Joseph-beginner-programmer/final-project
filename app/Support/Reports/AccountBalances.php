<?php

namespace App\Support\Reports;

use App\Enums\NormalBalance;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountBalances
{
    /**
     * @return array<int, string>
     */
    public static function net(?Carbon $from, Carbon $to): array
    {
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->when($from, fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $from->toDateString()))
            ->whereDate('journal_entries.entry_date', '<=', $to->toDateString())
            ->groupBy('journal_entry_lines.account_id')
            ->selectRaw('journal_entry_lines.account_id, SUM(journal_entry_lines.debit) - SUM(journal_entry_lines.credit) AS net')
            ->pluck('net', 'account_id')
            ->map(fn ($net) => bcadd((string) $net, '0', 2))
            ->all();
    }

    /**
     * @param  array<int, string>  $net
     */
    public static function natural(Account $account, array $net): string
    {
        $value = $net[$account->id] ?? '0.00';

        return $account->type->normalBalance() === NormalBalance::Debit ? $value : bcmul($value, '-1', 2);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    public static function sum(Collection $lines, string $key): string
    {
        return $lines->reduce(fn (string $carry, array $line) => bcadd($carry, $line[$key], 2), '0.00');
    }
}
