<?php

namespace App\Actions\Sales;

use App\Actions\Accounting\ReverseJournalEntryAction;
use App\Enums\DocumentStatus;
use App\Enums\JournalStatus;
use App\Exceptions\InvalidSalesPaymentException;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use Illuminate\Support\Facades\DB;

class CancelSalesPaymentAction
{
    public function __construct(private ReverseJournalEntryAction $reverseJournalEntry) {}

    public function handle(SalesPayment $payment, int $userId): SalesPayment
    {
        return DB::transaction(function () use ($payment, $userId) {
            $invoice = SalesInvoice::whereKey($payment->sales_invoice_id)->lockForUpdate()->firstOrFail();
            $payment = SalesPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== DocumentStatus::Posted) {
                throw new InvalidSalesPaymentException(InvalidSalesPaymentException::ALREADY_CANCELLED);
            }

            if (bccomp((string) $invoice->refunded_amount, '0', 2) > 0) {
                throw new InvalidSalesPaymentException(InvalidSalesPaymentException::HAS_REFUND);
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
