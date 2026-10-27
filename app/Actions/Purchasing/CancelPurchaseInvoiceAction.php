<?php

namespace App\Actions\Purchasing;

use App\Actions\Accounting\ReverseJournalEntryAction;
use App\Enums\DocumentStatus;
use App\Enums\JournalStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Exceptions\InvalidPurchaseInvoiceException;
use App\Models\PurchaseInvoice;
use Illuminate\Support\Facades\DB;

class CancelPurchaseInvoiceAction
{
    public function __construct(private ReverseJournalEntryAction $reverseJournalEntry) {}

    public function handle(PurchaseInvoice $invoice, int $userId): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $userId) {
            $invoice = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === PurchaseInvoiceStatus::Cancelled) {
                throw new InvalidPurchaseInvoiceException(InvalidPurchaseInvoiceException::NOT_POSTED);
            }

            if ($invoice->status->isPosted()) {
                if (\App\Models\PurchaseReturn::where('purchase_invoice_id', $invoice->id)->where('status', DocumentStatus::Posted->value)->exists()) {
                    throw new InvalidPurchaseInvoiceException(InvalidPurchaseInvoiceException::HAS_RETURNS);
                }
                if ($invoice->payments()->where('status', DocumentStatus::Posted->value)->exists()) {
                    throw new InvalidPurchaseInvoiceException(InvalidPurchaseInvoiceException::HAS_PAYMENTS);
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

            $invoice->status = PurchaseInvoiceStatus::Cancelled;
            $invoice->save();

            return $invoice;
        });
    }
}
