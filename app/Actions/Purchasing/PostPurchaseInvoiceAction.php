<?php

namespace App\Actions\Purchasing;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Enums\AccountSystemRole;
use App\Enums\JournalEntryType;
use App\Enums\PurchaseInvoiceStatus;
use App\Exceptions\InvalidPurchaseInvoiceException;
use App\Models\PurchaseInvoice;
use App\Support\AccountResolver;
use Illuminate\Support\Facades\DB;

class PostPurchaseInvoiceAction
{
    public function __construct(private PostJournalEntryAction $postJournalEntry) {}

    public function handle(PurchaseInvoice $invoice, int $userId): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $userId) {
            $invoice = PurchaseInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $invoice->ensureIsDraft();

            $other = PurchaseInvoice::query()
                ->where('purchase_order_receipt_batch_id', $invoice->purchase_order_receipt_batch_id)
                ->whereKeyNot($invoice->id)
                ->where('status', '!=', PurchaseInvoiceStatus::Cancelled->value)
                ->first();
            if ($other) {
                throw new InvalidPurchaseInvoiceException(InvalidPurchaseInvoiceException::ALREADY_INVOICED, $other->internal_number);
            }

            $invoice->load(['charges', 'supplier']);

            $lines = array_map(fn (array $line) => [
                'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                'debit' => $line['debit'],
                'credit' => $line['credit'],
            ], self::plan($invoice));

            $this->postJournalEntry->handle(
                lines: $lines,
                type: JournalEntryType::Auto,
                description: __('Purchase invoice :number from :supplier (:ref)', ['number' => $invoice->internal_number, 'supplier' => $invoice->supplier->supplier_name, 'ref' => $invoice->invoice_number]),
                userId: $userId,
                sourceType: 'purchase_invoice',
                sourceId: $invoice->id,
                entryDate: $invoice->invoice_date->toDateString(),
            );

            $invoice->status = PurchaseInvoiceStatus::Unpaid;
            $invoice->posted_by = $userId;
            $invoice->posted_at = now();
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public static function plan(PurchaseInvoice $invoice): array
    {
        $variance = (string) $invoice->price_variance;
        $lines = [
            ['role' => AccountSystemRole::GoodsReceivedNotInvoiced, 'account_id' => null, 'debit' => (string) $invoice->receipt_value, 'credit' => '0'],
        ];
        foreach ($invoice->charges as $charge) {
            $lines[] = ['role' => null, 'account_id' => $charge->account_id, 'debit' => (string) $charge->amount, 'credit' => '0'];
        }
        if (bccomp((string) $invoice->tax_amount, '0', 2) > 0) {
            $lines[] = ['role' => AccountSystemRole::VatIn, 'account_id' => null, 'debit' => (string) $invoice->tax_amount, 'credit' => '0'];
        }
        if (bccomp($variance, '0', 2) > 0) {
            $lines[] = ['role' => AccountSystemRole::PurchasePriceVariance, 'account_id' => null, 'debit' => $variance, 'credit' => '0'];
        }
        if (bccomp($variance, '0', 2) < 0) {
            $lines[] = ['role' => AccountSystemRole::PurchasePriceVariance, 'account_id' => null, 'debit' => '0', 'credit' => bcmul($variance, '-1', 2)];
        }
        $lines[] = ['role' => AccountSystemRole::AccountsPayable, 'account_id' => null, 'debit' => '0', 'credit' => (string) $invoice->total_amount];

        return $lines;
    }
}
