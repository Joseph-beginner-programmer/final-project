<?php

namespace App\Support;

use App\Enums\AccountSystemRole;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\Carbon;

class PpnPeriod
{
    public const CLOSING_SOURCE = 'ppn_closing';

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

    public function masukan(): string
    {
        return $this->balance(AccountSystemRole::VatIn);
    }

    public function keluaran(): string
    {
        return bcmul($this->balance(AccountSystemRole::VatOut), '-1', 2);
    }

    /**
     * Positive = kurang bayar (owed to the tax office). Negative = lebih bayar (credit carried forward).
     */
    public function net(): string
    {
        return bcsub($this->keluaran(), $this->masukan(), 2);
    }

    /**
     * @return list<array{role: AccountSystemRole, account_id: null, debit: string, credit: string}>
     */
    public function closingPlan(): array
    {
        $masukan = $this->masukan();
        $keluaran = $this->keluaran();

        if (bccomp($masukan, '0', 2) === 0 && bccomp($keluaran, '0', 2) === 0) {
            return [];
        }

        $lines = [];
        if (bccomp($keluaran, '0', 2) !== 0) {
            $lines[] = ['role' => AccountSystemRole::VatOut, 'account_id' => null, 'debit' => $keluaran, 'credit' => '0'];
        }
        if (bccomp($masukan, '0', 2) !== 0) {
            $lines[] = ['role' => AccountSystemRole::VatIn, 'account_id' => null, 'debit' => '0', 'credit' => $masukan];
        }

        $net = $this->net();
        if (bccomp($net, '0', 2) !== 0) {
            $lines[] = $this->line(AccountSystemRole::VatPayable, $net, false);
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
