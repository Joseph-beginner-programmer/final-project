<?php

namespace App\Actions\Accounting;

use App\Enums\AccountSystemRole;
use App\Enums\JournalEntryType;
use App\Exceptions\InvalidPpnEntryException;
use App\Models\JournalEntry;
use App\Support\AccountResolver;
use App\Support\PpnPeriod;
use Illuminate\Support\Facades\DB;

class ClosePpnPeriodAction
{
    public function __construct(private PostJournalEntryAction $postJournalEntry) {}

    public function handle(PpnPeriod $period, int $userId): JournalEntry
    {
        if (! $period->isFinished()) {
            throw new InvalidPpnEntryException(InvalidPpnEntryException::NOT_FINISHED, $period->label());
        }

        return DB::transaction(function () use ($period, $userId) {
            DB::table('accounts')->where('system_role', AccountSystemRole::VatPayable->value)->lockForUpdate()->first();

            if ($period->isClosed()) {
                throw new InvalidPpnEntryException(InvalidPpnEntryException::ALREADY_CLOSED, $period->label());
            }

            $plan = $period->closingPlan();
            if ($plan === []) {
                throw new InvalidPpnEntryException(InvalidPpnEntryException::NOTHING_TO_CLOSE, $period->label());
            }

            return $this->postJournalEntry->handle(
                lines: array_map(fn (array $line) => [
                    'account_id' => AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], $plan),
                type: JournalEntryType::Closing,
                description: __('PPN closing :period', ['period' => $period->label()]),
                userId: $userId,
                sourceType: PpnPeriod::CLOSING_SOURCE,
                sourceId: $period->key(),
                entryDate: $period->end->toDateString(),
            );
        });
    }
}
