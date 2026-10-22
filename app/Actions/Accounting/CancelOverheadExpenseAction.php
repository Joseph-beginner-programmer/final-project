<?php

namespace App\Actions\Accounting;

use App\Enums\JournalStatus;
use App\Exceptions\InvalidOverheadEntryException;
use App\Models\JournalEntry;
use App\Support\OverheadPeriod;
use Illuminate\Support\Facades\DB;

class CancelOverheadExpenseAction
{
    public function __construct(private ReverseJournalEntryAction $reverseJournalEntry) {}

    public function handle(JournalEntry $entry, int $userId): JournalEntry
    {
        if ($entry->source_type !== OverheadPeriod::EXPENSE_SOURCE || $entry->reversal_of_id !== null) {
            throw new InvalidOverheadEntryException(InvalidOverheadEntryException::NOT_AN_EXPENSE);
        }

        return DB::transaction(function () use ($entry, $userId) {
            $entry = JournalEntry::whereKey($entry->id)->lockForUpdate()->with('lines')->firstOrFail();

            if ($entry->status !== JournalStatus::Posted) {
                throw new InvalidOverheadEntryException(InvalidOverheadEntryException::ALREADY_CANCELLED);
            }

            $period = new OverheadPeriod(sprintf('%s-%s', substr((string) $entry->source_id, 0, 4), substr((string) $entry->source_id, 4, 2)));
            if ($period->isClosed()) {
                throw new InvalidOverheadEntryException(InvalidOverheadEntryException::ALREADY_CLOSED, $period->label());
            }

            return $this->reverseJournalEntry->handle($entry, $userId, $entry->entry_date->toDateString());
        });
    }
}
