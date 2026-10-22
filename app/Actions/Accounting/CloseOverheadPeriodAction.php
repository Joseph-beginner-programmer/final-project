<?php

namespace App\Actions\Accounting;

use App\Enums\AccountSystemRole;
use App\Enums\JournalEntryType;
use App\Exceptions\InvalidOverheadEntryException;
use App\Models\JournalEntry;
use App\Support\AccountResolver;
use App\Support\OverheadPeriod;
use Illuminate\Support\Facades\DB;

class CloseOverheadPeriodAction
{
    public function __construct(private PostJournalEntryAction $postJournalEntry) {}

    public function handle(OverheadPeriod $period, int $userId): JournalEntry
    {
        if (! $period->isFinished()) {
            throw new InvalidOverheadEntryException(InvalidOverheadEntryException::NOT_FINISHED, $period->label());
        }

        return DB::transaction(function () use ($period, $userId) {
            DB::table('accounts')->where('system_role', AccountSystemRole::OverheadApplied->value)->lockForUpdate()->first();

            if ($period->isClosed()) {
                throw new InvalidOverheadEntryException(InvalidOverheadEntryException::ALREADY_CLOSED, $period->label());
            }

            $plan = $period->closingPlan();
            if ($plan === []) {
                throw new InvalidOverheadEntryException(InvalidOverheadEntryException::NOTHING_TO_CLOSE, $period->label());
            }

            return $this->postJournalEntry->handle(
                lines: array_map(fn (array $line) => [
                    'account_id' => AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], $plan),
                type: JournalEntryType::Closing,
                description: __('Overhead closing :period', ['period' => $period->label()]),
                userId: $userId,
                sourceType: OverheadPeriod::CLOSING_SOURCE,
                sourceId: $period->key(),
                entryDate: $period->end->toDateString(),
            );
        });
    }
}
