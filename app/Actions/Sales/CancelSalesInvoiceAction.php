<?php

namespace App\Actions\Sales;

use App\Actions\Accounting\ReverseJournalEntryAction;
use App\Enums\DocumentStatus;
use App\Enums\JournalStatus;
use App\Enums\SalesInvoiceStatus;
use App\Exceptions\InvalidSalesInvoiceException;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

class CancelSalesInvoiceAction
{
    public function __construct(private ReverseJournalEntryAction $reverseJournalEntry) {}

    public function handle(SalesInvoice $invoice, int $userId): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $userId) {
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === SalesInvoiceStatus::Cancelled) {
                throw new InvalidSalesInvoiceException(InvalidSalesInvoiceException::NOT_POSTED);
            }

            if ($invoice->status->isPosted()) {
                if ($invoice->payments()->where('status', DocumentStatus::Posted->value)->exists()) {
                    throw new InvalidSalesInvoiceException(InvalidSalesInvoiceException::HAS_PAYMENTS);
                }

                $journal = $invoice->journalEntries()
                    ->whereNull('reversal_of_id')
                    ->where('status', JournalStatus::Posted->value)
                    ->with('lines')
                    ->first();
                if ($journal) {
                    $this->reverseJournalEntry->handle($journal, $userId);
                }
            }

            $invoice->status = SalesInvoiceStatus::Cancelled;
            $invoice->save();

            return $invoice;
        });
    }
}
