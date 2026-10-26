<?php

namespace App\Actions\Sales;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Enums\AccountSystemRole;
use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\JournalEntryType;
use App\Enums\PaymentMethod;
use App\Enums\SalesInvoiceStatus;
use App\Exceptions\InvalidSalesPaymentException;
use App\Models\Account;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Support\AccountResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordSalesPaymentAction
{
    public function __construct(private PostJournalEntryAction $postJournalEntry) {}

    public function handle(SalesInvoice $invoice, string $paymentDate, string $amount, PaymentMethod $method, int $cashAccountId, ?string $reference, int $userId): SalesPayment
    {
        if (! is_numeric($amount) || bccomp($amount, '0', 2) <= 0) {
            throw new InvalidSalesPaymentException(InvalidSalesPaymentException::INVALID_AMOUNT);
        }

        $date = Carbon::parse($paymentDate)->startOfDay();
        if ($date->gt(now()->startOfDay())) {
            throw new InvalidSalesPaymentException(InvalidSalesPaymentException::FUTURE_DATE);
        }

        $cashAccount = Account::find($cashAccountId);
        if (! $cashAccount || ! $cashAccount->is_active || $cashAccount->type !== AccountType::Asset || in_array($cashAccount->system_role, self::reservedRoles(), true)) {
            throw new InvalidSalesPaymentException(InvalidSalesPaymentException::NOT_CASH_ACCOUNT);
        }

        return DB::transaction(function () use ($invoice, $date, $amount, $method, $cashAccount, $reference, $userId) {
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! in_array($invoice->status, [SalesInvoiceStatus::Unpaid, SalesInvoiceStatus::PartiallyPaid], true)) {
                throw new InvalidSalesPaymentException(InvalidSalesPaymentException::NOT_PAYABLE);
            }
            if ($date->lt($invoice->invoice_date)) {
                throw new InvalidSalesPaymentException(InvalidSalesPaymentException::BEFORE_INVOICE);
            }
            if (bccomp($amount, $invoice->balance(), 2) > 0) {
                throw new InvalidSalesPaymentException(InvalidSalesPaymentException::EXCEEDS_BALANCE, 'Rp '.number_format((float) $invoice->balance(), 0, ',', '.'));
            }

            $payment = new SalesPayment([
                'sales_invoice_id' => $invoice->id,
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
            $payment->payment_number = sprintf('RCP-%s-%06d', $payment->created_at->format('Y'), $payment->id);
            $payment->save();

            $this->postJournalEntry->handle(
                lines: array_map(fn (array $line) => [
                    'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], self::plan((string) $payment->amount, $cashAccount->id)),
                type: JournalEntryType::Auto,
                description: __('Customer payment :number for sales invoice :invoice', ['number' => $payment->payment_number, 'invoice' => $invoice->invoice_number]),
                userId: $userId,
                sourceType: 'sales_payment',
                sourceId: $payment->id,
                entryDate: $payment->payment_date->toDateString(),
            );

            $invoice->syncPaymentStatus();

            return $payment;
        });
    }

    /**
     * @return list<AccountSystemRole>
     */
    public static function reservedRoles(): array
    {
        return [
            AccountSystemRole::InventoryRaw, AccountSystemRole::InventoryWip, AccountSystemRole::InventoryFinished, AccountSystemRole::InventorySemiFinished,
            AccountSystemRole::VatIn, AccountSystemRole::AccountsReceivable,
        ];
    }

    /**
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public static function plan(string $amount, ?int $cashAccountId): array
    {
        return [
            ['role' => null, 'account_id' => $cashAccountId, 'debit' => $amount, 'credit' => '0'],
            ['role' => AccountSystemRole::AccountsReceivable, 'account_id' => null, 'debit' => '0', 'credit' => $amount],
        ];
    }
}
