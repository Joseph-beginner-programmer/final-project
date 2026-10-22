<?php

namespace App\Actions\Purchasing;

use App\DTO\Purchasing\PurchaseInvoiceData;
use App\Enums\AccountType;
use App\Enums\PurchaseInvoiceStatus;
use App\Exceptions\InvalidPurchaseInvoiceException;
use App\Models\Account;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SavePurchaseInvoiceDraftAction
{
    public function handle(PurchaseOrderReceiptBatch $batch, PurchaseInvoiceData $data, int $userId, ?PurchaseInvoice $invoice = null): PurchaseInvoice
    {
        $invoice?->ensureIsDraft();

        $invoiceDate = Carbon::parse($data->invoiceDate)->startOfDay();
        if ($invoiceDate->gt(now()->startOfDay())) {
            throw new InvalidPurchaseInvoiceException(InvalidPurchaseInvoiceException::FUTURE_DATE);
        }
        if (Carbon::parse($data->dueDate)->startOfDay()->lt($invoiceDate)) {
            throw new InvalidPurchaseInvoiceException(InvalidPurchaseInvoiceException::DUE_BEFORE_INVOICE);
        }

        $charges = collect($data->charges)
            ->filter(fn (array $charge) => is_numeric($charge['amount']) && bccomp((string) $charge['amount'], '0', 2) > 0)
            ->values();
        foreach ($charges as $charge) {
            $account = Account::findOrFail($charge['account_id']);
            if ($account->type !== AccountType::Expense || ! $account->is_active) {
                throw new InvalidPurchaseInvoiceException(InvalidPurchaseInvoiceException::NOT_EXPENSE_ACCOUNT, $account->account_name);
            }
        }
        $otherCharges = $charges->reduce(fn (string $carry, array $charge) => bcadd($carry, (string) $charge['amount'], 2), '0');

        return DB::transaction(function () use ($batch, $data, $userId, $invoice, $charges, $otherCharges) {
            PurchaseOrderReceiptBatch::whereKey($batch->id)->lockForUpdate()->first();

            $other = $batch->purchaseInvoices()
                ->where('status', '!=', PurchaseInvoiceStatus::Cancelled->value)
                ->when($invoice, fn ($query) => $query->whereKeyNot($invoice->id))
                ->first();
            if ($other) {
                throw new InvalidPurchaseInvoiceException(InvalidPurchaseInvoiceException::ALREADY_INVOICED, $other->internal_number);
            }

            $lines = self::lines($batch, $data->unitPrices);
            $totals = self::totals($lines, $data->capitalizedCharges, $otherCharges, $data->taxRate);

            $fields = [
                'invoice_number' => $data->invoiceNumber,
                'purchase_order_id' => $batch->purchase_order_id,
                'purchase_order_receipt_batch_id' => $batch->id,
                'supplier_id' => $batch->purchaseOrder->supplier_id,
                'invoice_date' => $data->invoiceDate,
                'due_date' => $data->dueDate,
                'tax_rate' => is_numeric($data->taxRate) ? $data->taxRate : '0',
                'notes' => filled($data->notes) ? $data->notes : null,
                ...$totals,
            ];

            if ($invoice) {
                $invoice->update($fields);
            } else {
                $invoice = new PurchaseInvoice([...$fields, 'created_by' => $userId]);
                $invoice->status = PurchaseInvoiceStatus::Draft;
                $invoice->internal_number = (string) Str::uuid();
                $invoice->save();
                $invoice->internal_number = sprintf('PI-%s-%06d', $invoice->created_at->format('Y'), $invoice->id);
                $invoice->save();
            }

            $invoice->items()->delete();
            foreach ($lines as $line) {
                $invoice->items()->create($line);
            }

            $invoice->charges()->delete();
            foreach ($charges as $charge) {
                $invoice->charges()->create([
                    'description' => $charge['description'],
                    'account_id' => $charge['account_id'],
                    'amount' => $charge['amount'],
                ]);
            }

            return $invoice->load(['items', 'charges']);
        });
    }

    /**
     * @param  array<int, string>  $unitPrices
     * @return list<array{purchase_order_receipt_id: int, product_id: int, quantity: string, unit_price: string, amount: string, received_value: string}>
     */
    public static function lines(PurchaseOrderReceiptBatch $batch, array $unitPrices = []): array
    {
        $receipts = $batch->purchaseOrderReceipts()->with(['purchaseOrderItem', 'inventoryLot'])->orderBy('id')->get();

        if ($receipts->isEmpty()) {
            throw new InvalidPurchaseInvoiceException(InvalidPurchaseInvoiceException::EMPTY_RECEIPT);
        }

        return $receipts->map(function (PurchaseOrderReceipt $receipt) use ($unitPrices) {
            $price = $unitPrices[$receipt->id] ?? (string) $receipt->purchaseOrderItem->unit_price;
            if (! is_numeric($price) || bccomp((string) $price, '0', 2) < 0) {
                throw new InvalidPurchaseInvoiceException(InvalidPurchaseInvoiceException::INVALID_LINE);
            }

            return [
                'purchase_order_receipt_id' => $receipt->id,
                'product_id' => $receipt->purchaseOrderItem->product_id,
                'quantity' => (string) $receipt->quantity_received,
                'unit_price' => bcadd((string) $price, '0', 2),
                'amount' => bcadd(bcmul((string) $receipt->quantity_received, (string) $price, 6), '0.005', 2),
                'received_value' => $receipt->receivedValue(),
            ];
        })->all();
    }

    /**
     * @param  list<array{amount: string, received_value: string}>  $lines
     * @return array{goods_amount: string, capitalized_charges: string, other_charges: string, subtotal: string, receipt_value: string, price_variance: string, tax_amount: string, total_amount: string}
     */
    public static function totals(array $lines, string $capitalizedCharges, string $otherCharges, string $taxRate): array
    {
        $positive = fn (string $value) => is_numeric($value) && bccomp($value, '0', 2) > 0 ? bcadd($value, '0', 2) : '0';
        $goods = array_reduce($lines, fn (string $carry, array $line) => bcadd($carry, $line['amount'], 2), '0');
        $receiptValue = array_reduce($lines, fn (string $carry, array $line) => bcadd($carry, $line['received_value'], 2), '0');
        $capitalized = $positive($capitalizedCharges);
        $other = $positive($otherCharges);
        $subtotal = bcadd(bcadd($goods, $capitalized, 2), $other, 2);
        $rate = is_numeric($taxRate) && bccomp($taxRate, '0', 2) > 0 ? $taxRate : '0';
        $tax = bcadd(bcdiv(bcmul($subtotal, $rate, 6), '100', 6), '0.005', 2);

        return [
            'goods_amount' => $goods,
            'capitalized_charges' => $capitalized,
            'other_charges' => $other,
            'subtotal' => $subtotal,
            'receipt_value' => $receiptValue,
            'price_variance' => bcsub(bcadd($goods, $capitalized, 2), $receiptValue, 2),
            'tax_amount' => $tax,
            'total_amount' => bcadd($subtotal, $tax, 2),
        ];
    }

    public static function defaultCapitalizedCharges(PurchaseOrderReceiptBatch $batch): string
    {
        $share = array_reduce(self::lines($batch), fn (string $carry, array $line) => bcadd($carry, bcsub($line['received_value'], $line['amount'], 2), 2), '0');

        return bccomp($share, '0', 2) > 0 ? $share : '0';
    }
}
