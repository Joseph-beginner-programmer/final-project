<?php

namespace App\Actions\Purchasing;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Enums\AccountSystemRole;
use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\JournalEntryType;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseInvoiceStatus;
use App\Exceptions\InvalidPurchasePaymentException;
use App\Models\Account;
use App\Models\PurchaseInvoice;
use App\Models\PurchasePayment;
use App\Support\AccountResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordPurchasePaymentAction
{
    public function __construct(private PostJournalEntryAction $postJournalEntry) {}

    public function handle(PurchaseInvoice $invoice, string $paymentDate, string $amount, PaymentMethod $method, int $cashAccountId, ?string $reference, int $userId): PurchasePayment
    {
        if (! is_numeric($amount) || bccomp($amount, '0', 2) <= 0) {
            throw new InvalidPurchasePaymentException(InvalidPurchasePaymentException::INVALID_AMOUNT);
        }

        $date = Carbon::parse($paymentDate)->startOfDay();
        if ($date->gt(now()->startOfDay())) {
            throw new InvalidPurchasePaymentException(InvalidPurchasePaymentException::FUTURE_DATE);
        }

        $cashAccount = Account::find($cashAccountId);
        $reserved = [AccountSystemRole::InventoryRaw, AccountSystemRole::InventoryWip, AccountSystemRole::InventoryFinished, AccountSystemRole::VatIn];
        if (! $cashAccount || ! $cashAccount->is_active || $cashAccount->type !== AccountType::Asset || in_array($cashAccount->system_role, $reserved, true)) {
            throw new InvalidPurchasePaymentException(InvalidPurchasePaymentException::NOT_CASH_ACCOUNT);
        }

        return DB::transaction(function () use ($invoice, $date, $amount, $method, $cashAccount, $reference, $userId) {
            $invoice = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! in_array($invoice->status, [PurchaseInvoiceStatus::Unpaid, PurchaseInvoiceStatus::PartiallyPaid], true)) {
                throw new InvalidPurchasePaymentException(InvalidPurchasePaymentException::NOT_PAYABLE);
            }
            if ($date->lt($invoice->invoice_date)) {
                throw new InvalidPurchasePaymentException(InvalidPurchasePaymentException::BEFORE_INVOICE);
            }
            if (bccomp($amount, $invoice->balance(), 2) > 0) {
                throw new InvalidPurchasePaymentException(InvalidPurchasePaymentException::EXCEEDS_BALANCE, 'Rp '.number_format((float) $invoice->balance(), 0, ',', '.'));
            }

            $payment = new PurchasePayment([
                'purchase_invoice_id' => $invoice->id,
                'payment_date' => $date->toDateString(),
                'amount' => bcadd($amount, '0', 2),
                'method' => $method,
                'cash_account_id' => $cashAccount->id,
                'reference' => filled($reference) ? $reference : null,
                'created_by' => $userId,
            ]);
            $payment->status = DocumentStatus::Posted;
            $payment->payment_number = (string) Str::uuid();
            $payment->save();
            $payment->payment_number = sprintf('PAY-%s-%06d', $payment->created_at->format('Y'), $payment->id);
            $payment->save();

            $this->postJournalEntry->handle(
                lines: array_map(fn (array $line) => [
                    'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], self::plan((string) $payment->amount, $cashAccount->id)),
                type: JournalEntryType::Auto,
                description: __('Payment :number for purchase invoice :invoice', ['number' => $payment->payment_number, 'invoice' => $invoice->internal_number]),
                userId: $userId,
                sourceType: 'purchase_payment',
                sourceId: $payment->id,
                entryDate: $payment->payment_date->toDateString(),
            );

            $invoice->syncPaymentStatus();

            return $payment;
        });
    }

    /**
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public static function plan(string $amount, ?int $cashAccountId): array
    {
        return [
            ['role' => AccountSystemRole::AccountsPayable, 'account_id' => null, 'debit' => $amount, 'credit' => '0'],
            ['role' => null, 'account_id' => $cashAccountId, 'debit' => '0', 'credit' => $amount],
        ];
    }
}
