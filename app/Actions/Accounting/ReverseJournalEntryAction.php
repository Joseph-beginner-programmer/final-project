<?php

namespace App\Actions\Accounting;

use App\Enums\JournalEntryType;
use App\Enums\JournalStatus;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

class ReverseJournalEntryAction
{
    public function __construct(
        private PostJournalEntryAction $postJournalEntry,
    ) {}

    public function handle(JournalEntry $entry, int $userId, ?string $entryDate = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $userId, $entryDate) {
            $entry->transitionTo(JournalStatus::Reversed);

            $lines = $entry->lines->map(fn ($line) => [
                'account_id' => $line->account_id,
                'debit' => (string) $line->credit,
                'credit' => (string) $line->debit,
            ])->all();

            $reversal = $this->postJournalEntry->handle(
                lines: $lines,
                type: JournalEntryType::Adjustment,
                description: __('Reversal of :number', ['number' => $entry->journal_number]),
                userId: $userId,
                sourceType: $entry->source_type,
                sourceId: $entry->source_id,
                entryDate: $entryDate,
            );

            $reversal->reversal_of_id = $entry->id;
            $reversal->save();

            return $reversal;
        });
    }
}
