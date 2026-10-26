<?php

namespace App\Actions\Accounting;

use App\Enums\DocumentStatus;
use App\Enums\JournalStatus;
use App\Exceptions\InvalidWagePaymentException;
use App\Models\WagePayment;
use Illuminate\Support\Facades\DB;

class CancelWagePaymentAction
{
    public function __construct(private ReverseJournalEntryAction $reverseJournalEntry) {}

    public function handle(WagePayment $payment, int $userId): WagePayment
    {
        return DB::transaction(function () use ($payment, $userId) {
            $payment = WagePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== DocumentStatus::Posted) {
                throw new InvalidWagePaymentException(InvalidWagePaymentException::ALREADY_CANCELLED);
            }

            $journal = $payment->journalEntries()
                ->whereNull('reversal_of_id')
                ->where('status', JournalStatus::Posted->value)
                ->with('lines')
                ->first();
            if ($journal) {
                $this->reverseJournalEntry->handle($journal, $userId);
            }

            DB::table('production_result_labors')->where('wage_payment_id', $payment->id)->update(['wage_payment_id' => null]);

            $payment->status = DocumentStatus::Cancelled;
            $payment->cancelled_by = $userId;
            $payment->cancelled_at = now();
            $payment->save();

            return $payment;
        });
    }
}
