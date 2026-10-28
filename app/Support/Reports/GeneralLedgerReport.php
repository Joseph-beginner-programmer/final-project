<?php

namespace App\Support\Reports;

use App\Enums\JournalStatus;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\JournalEntryLine;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GeneralLedgerReport
{
    public function __construct(
        public readonly Account $account,
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    public function opening(): string
    {
        $sums = JournalEntryLine::query()
            ->where('account_id', $this->account->id)
            ->whereHas('journalEntry', fn ($q) => $q->whereDate('entry_date', '<', $this->start->toDateString()))
            ->selectRaw('COALESCE(SUM(debit), 0) AS d, COALESCE(SUM(credit), 0) AS c')
            ->first();

        return $this->signed((string) $sums->d, (string) $sums->c);
    }

    /**
     * @return Collection<int, array{date: Carbon, journal_id: int, journal_number: string, description: string, counter: string, tag: ?string, debit: string, credit: string, balance: string}>
     */
    public function rows(): Collection
    {
        $balance = $this->opening();

        return JournalEntryLine::query()
            ->where('account_id', $this->account->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereDate('journal_entries.entry_date', '>=', $this->start->toDateString())
            ->whereDate('journal_entries.entry_date', '<=', $this->end->toDateString())
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_entry_lines.id')
            ->select('journal_entry_lines.*')
            ->with('journalEntry.lines.account')
            ->get()
            ->map(function (JournalEntryLine $line) use (&$balance) {
                $entry = $line->journalEntry;
                $balance = bcadd($balance, $this->signed((string) $line->debit, (string) $line->credit), 2);
                $isDebit = bccomp((string) $line->debit, '0', 2) > 0;
                $counter = $entry->lines
                    ->filter(fn ($other) => $other->account_id !== $this->account->id && (bccomp((string) $other->debit, '0', 2) > 0) !== $isDebit)
                    ->map(fn ($other) => $other->account->account_name)
                    ->unique()
                    ->values();

                return [
                    'date' => $entry->entry_date,
                    'journal_id' => $entry->id,
                    'journal_number' => $entry->journal_number,
                    'description' => $entry->description,
                    'counter' => $counter->count() > 2 ? $counter->take(2)->implode(', ').' +'.($counter->count() - 2) : $counter->implode(', '),
                    'tag' => $entry->reversal_of_id ? 'reversal' : ($entry->status === JournalStatus::Reversed ? 'reversed' : null),
                    'debit' => bcadd((string) $line->debit, '0', 2),
                    'credit' => bcadd((string) $line->credit, '0', 2),
                    'balance' => $balance,
                ];
            });
    }

    /**
     * @param  Collection<int, array{debit: string, credit: string, balance: string}>  $rows
     * @return array{opening: string, debit: string, credit: string, closing: string}
     */
    public function summary(Collection $rows): array
    {
        $opening = $this->opening();
        $debit = $rows->reduce(fn (string $carry, array $row) => bcadd($carry, $row['debit'], 2), '0');
        $credit = $rows->reduce(fn (string $carry, array $row) => bcadd($carry, $row['credit'], 2), '0');

        return [
            'opening' => $opening,
            'debit' => $debit,
            'credit' => $credit,
            'closing' => bcadd($opening, $this->signed($debit, $credit), 2),
        ];
    }

    public function signed(string $debit, string $credit): string
    {
        return $this->account->normal_balance === NormalBalance::Debit
            ? bcsub($debit, $credit, 2)
            : bcsub($credit, $debit, 2);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<list<string>>
     */
    public function excel(Collection $rows): array
    {
        return [
            ['', '', __('Opening balance'), '', '', '', Excel::number($this->opening())],
            ...$rows->map(fn (array $row) => [
                $row['date']->format('Y-m-d'),
                $row['journal_number'],
                $row['description'],
                $row['counter'],
                Excel::number($row['debit']),
                Excel::number($row['credit']),
                Excel::number($row['balance']),
            ])->all(),
        ];
    }
}
