<?php

namespace App\Support;

use App\Models\Account;

class JournalPreview
{
    /**
     * @return list<array{role: null, account_id: int, debit: string, credit: string}>
     */
    public static function reversalOf(?\App\Models\JournalEntry $entry): array
    {
        if (! $entry) {
            return [];
        }

        return $entry->lines()->get()->map(fn ($line) => [
            'role' => null,
            'account_id' => $line->account_id,
            'debit' => (string) $line->credit,
            'credit' => (string) $line->debit,
        ])->all();
    }

    /**
     * @param  list<array{role: ?\App\Enums\AccountSystemRole, account_id: ?int, debit: string, credit: string}>  $plan
     * @param  list<string>|null  $sides
     * @return list<array{name: string, code: ?string, unmapped: bool, pending: bool, side: string, debit: string, credit: string}>
     */
    public static function resolve(array $plan, ?array $sides = null, ?string $pendingLabel = null): array
    {
        $byId = Account::whereIn('id', collect($plan)->pluck('account_id')->filter()->all())->get()->keyBy('id');
        $byRole = Account::whereNotNull('system_role')->get()->keyBy(fn (Account $a) => $a->system_role->value);

        return collect($plan)->values()->map(function (array $line, int $i) use ($byId, $byRole, $sides, $pendingLabel) {
            $pending = $line['role'] === null && $line['account_id'] === null;
            $account = $pending ? null : ($line['account_id'] ? $byId->get($line['account_id']) : $byRole->get($line['role']->value));

            return [
                'name' => $pending ? ($pendingLabel ?? __('Choose an account')) : ($account?->account_name ?? $line['role']->label()),
                'code' => $account?->account_code,
                'unmapped' => ! $pending && $account === null,
                'pending' => $pending,
                'side' => $sides[$i] ?? (bccomp($line['credit'], '0', 2) > 0 ? 'credit' : 'debit'),
                'debit' => $line['debit'],
                'credit' => $line['credit'],
            ];
        })->all();
    }
}
