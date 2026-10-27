<?php

namespace App\Actions\Purchasing;

use App\Actions\Accounting\ReverseJournalEntryAction;
use App\Enums\DocumentStatus;
use App\Enums\JournalStatus;
use App\Exceptions\InvalidPurchasePaymentException;
use App\Models\PurchaseInvoice;
use App\Models\PurchasePayment;
use Illuminate\Support\Facades\DB;

class CancelPurchasePaymentAction
{
    public function __construct(private ReverseJournalEntryAction $reverseJournalEntry) {}

    public function handle(PurchasePayment $payment, int $userId): PurchasePayment
    {
        return DB::transaction(function () use ($payment, $userId) {
            $invoice = PurchaseInvoice::whereKey($payment->purchase_invoice_id)->lockForUpdate()->firstOrFail();
            $payment = PurchasePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== DocumentStatus::Posted) {
                throw new InvalidPurchasePaymentException(InvalidPurchasePaymentException::ALREADY_CANCELLED);
            }

            if (bccomp((string) $invoice->refunded_amount, '0', 2) > 0) {
                throw new InvalidPurchasePaymentException(InvalidPurchasePaymentException::HAS_REFUND);
            }

            $journal = $payment->journalEntries()
                ->whereNull('reversal_of_id')
                ->where('status', JournalStatus::Posted->value)
                ->with('lines')
                ->first();
            if ($journal) {
                $this->reverseJournalEntry->handle($journal, $userId);
            }

            $payment->status = DocumentStatus::Cancelled;
            $payment->cancelled_by = $userId;
            $payment->cancelled_at = now();
            $payment->save();

            $invoice->syncPaymentStatus();

            return $payment;
        });
    }
}
