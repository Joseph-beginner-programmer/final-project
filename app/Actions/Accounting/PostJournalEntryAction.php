<?php

namespace App\Actions\Accounting;

use App\Enums\JournalEntryType;
use App\Enums\JournalStatus;
use App\Exceptions\InvalidJournalEntryException;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostJournalEntryAction
{
    /**
     * @param  list<array{account_id: int, debit: string, credit: string}>  $lines
     */
    public function handle(
        array $lines,
        JournalEntryType $type,
        string $description,
        int $userId,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $entryDate = null,
    ): JournalEntry {
        $lines = array_values(array_filter($lines, fn (array $line) => bccomp($line['debit'] ?: '0', '0', 2) > 0 || bccomp($line['credit'] ?: '0', '0', 2) > 0));

        if (count($lines) < 2) {
            throw new InvalidJournalEntryException(InvalidJournalEntryException::NOT_ENOUGH_LINES);
        }

        $totalDebit = '0';
        $totalCredit = '0';
        foreach ($lines as $line) {
            $debit = $line['debit'] ?: '0';
            $credit = $line['credit'] ?: '0';

            if (bccomp($debit, '0', 2) > 0 && bccomp($credit, '0', 2) > 0) {
                throw new InvalidJournalEntryException(InvalidJournalEntryException::LINE_MUST_PICK_ONE_SIDE);
            }

            $totalDebit = bcadd($totalDebit, $debit, 2);
            $totalCredit = bcadd($totalCredit, $credit, 2);
        }

        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw new InvalidJournalEntryException(InvalidJournalEntryException::UNBALANCED);
        }

        return DB::transaction(function () use ($lines, $type, $description, $userId, $sourceType, $sourceId, $entryDate) {
            $entry = new JournalEntry([
                'entry_date' => $entryDate ?? now()->toDateString(),
                'description' => $description,
                'entry_type' => $type,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'created_by' => $userId,
            ]);

            $entry->journal_number = (string) Str::uuid();
            $entry->status = JournalStatus::Posted;
            $entry->save();

            $entry->journal_number = sprintf('JRN-%s-%06d', $entry->entry_date->format('Y'), $entry->id);
            $entry->save();

            foreach ($lines as $line) {
                $entryLine = new JournalEntryLine([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'] ?: '0',
                    'credit' => $line['credit'] ?: '0',
                ]);
                $entryLine->save();
            }

            return $entry->load('lines.account');
        });
    }
}
