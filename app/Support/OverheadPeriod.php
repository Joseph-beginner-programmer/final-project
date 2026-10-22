<?php

namespace App\Support;

use App\Enums\AccountSystemRole;
use App\Enums\JournalStatus;
use App\Enums\OverheadCategory;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\WorkCenter;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class OverheadPeriod
{
    public const EXPENSE_SOURCE = 'overhead_expense';

    public const CLOSING_SOURCE = 'overhead_closing';

    public readonly Carbon $start;

    public readonly Carbon $end;

    public function __construct(public readonly string $period)
    {
        $this->start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $this->end = $this->start->copy()->endOfMonth();
    }

    public static function of(Carbon $date): self
    {
        return new self($date->format('Y-m'));
    }

    public function key(): int
    {
        return (int) $this->start->format('Ym');
    }

    public function label(): string
    {
        return $this->start->translatedFormat('F Y');
    }

    public function isFinished(): bool
    {
        return $this->end->lt(now()->startOfDay());
    }

    public function isFuture(): bool
    {
        return $this->start->gt(now());
    }

    public function closingEntry(): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', self::CLOSING_SOURCE)
            ->where('source_id', $this->key())
            ->where('status', JournalStatus::Posted->value)
            ->whereNull('reversal_of_id')
            ->first();
    }

    public function isClosed(): bool
    {
        return $this->closingEntry() !== null;
    }

    public function expenseDate(): string
    {
        return ($this->end->lt(now()) ? $this->end : now())->toDateString();
    }

    /**
     * @return Collection<int, JournalEntry>
     */
    public function expenses(): Collection
    {
        return JournalEntry::query()
            ->where('source_type', self::EXPENSE_SOURCE)
            ->where('source_id', $this->key())
            ->with(['lines.account', 'createdBy'])
            ->orderBy('id')
            ->get();
    }

    public function balance(AccountSystemRole $role): string
    {
        $account = Account::where('system_role', $role->value)->first();

        if (! $account) {
            return '0';
        }

        $totals = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q
                ->whereBetween('entry_date', [$this->start->toDateString(), $this->end->toDateString()])
                ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', self::CLOSING_SOURCE)))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        return bcsub((string) $totals->debit, (string) $totals->credit, 2);
    }

    public function applied(): string
    {
        return bcmul($this->balance(AccountSystemRole::OverheadApplied), '-1', 2);
    }

    /**
     * @return array<string, string>
     */
    public function budget(): array
    {
        $budget = array_fill_keys(array_map(fn (OverheadCategory $c) => $c->value, OverheadCategory::cases()), '0');

        foreach (WorkCenter::all() as $center) {
            $rate = $center->overheadRateOn(\Illuminate\Support\Carbon::instance($this->end));
            foreach ($rate?->components ?? [] as $component) {
                $budget[$component->category->value] = bcadd($budget[$component->category->value], (string) $component->amount, 2);
            }
        }

        return $budget;
    }

    /**
     * @return list<array{category: OverheadCategory, budget: string, actual: string, entries: int}>
     */
    public function categories(): array
    {
        $budget = $this->budget();
        $counts = $this->expenses()
            ->filter(fn (JournalEntry $e) => $e->reversal_of_id === null && $e->status === JournalStatus::Posted)
            ->flatMap(fn (JournalEntry $e) => $e->lines->filter(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0)->map(fn ($l) => $l->account?->system_role?->value))
            ->countBy();

        return array_map(fn (OverheadCategory $category) => [
            'category' => $category,
            'budget' => $budget[$category->value],
            'actual' => $this->balance($category->systemRole()),
            'entries' => $counts[$category->systemRole()->value] ?? 0,
        ], OverheadCategory::cases());
    }

    public function actualTotal(): string
    {
        return array_reduce(OverheadCategory::cases(), fn (string $carry, OverheadCategory $c) => bcadd($carry, $this->balance($c->systemRole()), 2), '0');
    }

    /**
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public function closingPlan(): array
    {
        $lines = [];
        $applied = $this->applied();
        if (bccomp($applied, '0', 2) !== 0) {
            $lines[] = $this->line(AccountSystemRole::OverheadApplied, $applied, true);
        }

        foreach (OverheadCategory::cases() as $category) {
            $actual = $this->balance($category->systemRole());
            if (bccomp($actual, '0', 2) !== 0) {
                $lines[] = $this->line($category->systemRole(), $actual, false);
            }
        }

        $variance = bcsub($this->actualTotal(), $applied, 2);
        if (bccomp($variance, '0', 2) !== 0) {
            $lines[] = $this->line(AccountSystemRole::Cogs, $variance, true);
        }

        return $lines;
    }

    /**
     * @return array{role: AccountSystemRole, account_id: null, debit: string, credit: string}
     */
    protected function line(AccountSystemRole $role, string $amount, bool $debitWhenPositive): array
    {
        $positive = bccomp($amount, '0', 2) > 0;
        $abs = $positive ? $amount : bcmul($amount, '-1', 2);
        $debit = $positive === $debitWhenPositive;

        return ['role' => $role, 'account_id' => null, 'debit' => $debit ? $abs : '0', 'credit' => $debit ? '0' : $abs];
    }
}
