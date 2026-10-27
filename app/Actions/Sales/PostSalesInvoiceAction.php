<?php

namespace App\Actions\Sales;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Enums\AccountSystemRole;
use App\Enums\JournalEntryType;
use App\Enums\SalesInvoiceStatus;
use App\Exceptions\InvalidSalesInvoiceException;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Support\AccountResolver;
use Illuminate\Support\Facades\DB;

class PostSalesInvoiceAction
{
    public function __construct(private PostJournalEntryAction $postJournalEntry) {}

    public function handle(SalesInvoice $invoice, int $userId): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $userId) {
            SalesOrder::whereKey($invoice->sales_order_id)->lockForUpdate()->first();
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $invoice->ensureIsDraft();

            $other = SalesInvoice::query()
                ->where('sales_delivery_id', $invoice->sales_delivery_id)
                ->whereKeyNot($invoice->id)
                ->where('status', '!=', SalesInvoiceStatus::Cancelled->value)
                ->first();
            if ($other) {
                throw new InvalidSalesInvoiceException(InvalidSalesInvoiceException::ALREADY_INVOICED, $other->invoice_number);
            }

            $delivery = $invoice->salesDelivery;
            SaveSalesInvoiceDraftAction::checkDates($delivery, $invoice->invoice_date->toDateString(), $invoice->due_date->toDateString());
            if (SaveSalesInvoiceDraftAction::lines($delivery) === []) {
                throw new InvalidSalesInvoiceException(InvalidSalesInvoiceException::FULLY_RETURNED, $delivery->delivery_number);
            }
            $invoice->fill(SaveSalesInvoiceDraftAction::totals($delivery, (string) $invoice->tax_rate, $invoice->id));

            $this->postJournalEntry->handle(
                lines: array_map(fn (array $line) => [
                    'account_id' => AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], self::plan($invoice)),
                type: JournalEntryType::Auto,
                description: __('Sales invoice :number to :customer (:delivery)', ['number' => $invoice->invoice_number, 'customer' => $invoice->customer->customer_name, 'delivery' => $delivery->delivery_number]),
                userId: $userId,
                sourceType: 'sales_invoice',
                sourceId: $invoice->id,
                entryDate: $invoice->invoice_date->toDateString(),
            );

            $invoice->status = SalesInvoiceStatus::Unpaid;
            $invoice->posted_by = $userId;
            $invoice->posted_at = now();
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * @return list<array{role: AccountSystemRole, account_id: null, debit: string, credit: string}>
     */
    public static function preview(SalesInvoice $invoice): array
    {
        $fresh = $invoice->replicate();
        $fresh->fill(SaveSalesInvoiceDraftAction::totals($invoice->salesDelivery, (string) $invoice->tax_rate, $invoice->id));

        return self::plan($fresh);
    }

    /**
     * @return list<array{role: AccountSystemRole, account_id: null, debit: string, credit: string}>
     */
    public static function plan(SalesInvoice $invoice): array
    {
        $lines = [
            ['role' => AccountSystemRole::AccountsReceivable, 'account_id' => null, 'debit' => (string) $invoice->total_amount, 'credit' => '0'],
            ['role' => AccountSystemRole::SalesRevenue, 'account_id' => null, 'debit' => '0', 'credit' => (string) $invoice->subtotal],
        ];
        if (bccomp((string) $invoice->tax_amount, '0', 2) > 0) {
            $lines[] = ['role' => AccountSystemRole::VatOut, 'account_id' => null, 'debit' => '0', 'credit' => (string) $invoice->tax_amount];
        }

        return $lines;
    }
}
