<?php

namespace App\Actions\Sales;

use App\Enums\DocumentStatus;
use App\Enums\SalesInvoiceStatus;
use App\Exceptions\InvalidSalesInvoiceException;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveSalesInvoiceDraftAction
{
    public function handle(SalesDelivery $delivery, string $invoiceDate, string $dueDate, string $taxRate, int $userId, ?SalesInvoice $invoice = null): SalesInvoice
    {
        $invoice?->ensureIsDraft();
        self::checkDates($delivery, $invoiceDate, $dueDate);

        return DB::transaction(function () use ($delivery, $invoiceDate, $dueDate, $taxRate, $userId, $invoice) {
            SalesOrder::whereKey($delivery->sales_order_id)->lockForUpdate()->first();
            $delivery = SalesDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            $other = $delivery->salesInvoices()
                ->where('status', '!=', SalesInvoiceStatus::Cancelled->value)
                ->when($invoice, fn ($query) => $query->whereKeyNot($invoice->id))
                ->first();
            if ($other) {
                throw new InvalidSalesInvoiceException(InvalidSalesInvoiceException::ALREADY_INVOICED, $other->invoice_number);
            }

            $fields = [
                'sales_order_id' => $delivery->sales_order_id,
                'sales_delivery_id' => $delivery->id,
                'customer_id' => $delivery->salesOrder->customer_id,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                ...self::totals($delivery, $taxRate, $invoice?->id),
            ];

            if ($invoice) {
                $invoice->update($fields);

                return $invoice;
            }

            $invoice = new SalesInvoice([...$fields, 'created_by' => $userId]);
            $invoice->status = SalesInvoiceStatus::Draft;
            $invoice->invoice_number = (string) Str::uuid();
            $invoice->save();
            $invoice->invoice_number = sprintf('SI-%s-%06d', $invoice->created_at->format('Y'), $invoice->id);
            $invoice->save();

            return $invoice;
        });
    }

    public static function checkDates(SalesDelivery $delivery, string $invoiceDate, string $dueDate): void
    {
        if ($delivery->status !== DocumentStatus::Posted || ! $delivery->delivered_at) {
            throw new InvalidSalesInvoiceException(InvalidSalesInvoiceException::NOT_DELIVERED);
        }

        $date = Carbon::parse($invoiceDate)->startOfDay();
        if ($date->gt(now()->startOfDay())) {
            throw new InvalidSalesInvoiceException(InvalidSalesInvoiceException::FUTURE_DATE);
        }
        if ($date->lt($delivery->delivered_at->copy()->startOfDay())) {
            throw new InvalidSalesInvoiceException(InvalidSalesInvoiceException::BEFORE_DELIVERY, $delivery->delivered_at->translatedFormat('d M Y'));
        }
        if (Carbon::parse($dueDate)->startOfDay()->lt($date)) {
            throw new InvalidSalesInvoiceException(InvalidSalesInvoiceException::DUE_BEFORE_INVOICE);
        }
    }

    /**
     * @return list<array{product_id: int, product_name: string, product_code: string, unit: string, quantity: string, unit_price: string, amount: string}>
     */
    public static function lines(SalesDelivery $delivery): array
    {
        return $delivery->items()->with(['product', 'salesOrderItem'])->orderBy('id')->get()
            ->map(fn (SalesDeliveryItem $item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product->product_name,
                'product_code' => $item->product->product_code,
                'unit' => $item->product->unit_of_measure,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->salesOrderItem->unit_price,
                'amount' => bcadd(bcmul((string) $item->quantity, (string) $item->salesOrderItem->unit_price, 6), '0.005', 2),
            ])
            ->all();
    }

    public static function chargesBilledElsewhere(int $salesOrderId, ?int $exceptInvoiceId): bool
    {
        return SalesInvoice::query()
            ->where('sales_order_id', $salesOrderId)
            ->whereIn('status', [SalesInvoiceStatus::Unpaid->value, SalesInvoiceStatus::PartiallyPaid->value, SalesInvoiceStatus::Paid->value])
            ->when($exceptInvoiceId, fn ($query) => $query->whereKeyNot($exceptInvoiceId))
            ->where('charges_amount', '>', 0)
            ->exists();
    }

    /**
     * @return array{goods_amount: string, discount_percent: string, discount_amount: string, charges_amount: string, subtotal: string, tax_rate: string, tax_amount: string, total_amount: string}
     */
    public static function totals(SalesDelivery $delivery, string $taxRate, ?int $exceptInvoiceId = null): array
    {
        $order = $delivery->salesOrder()->with('charges')->firstOrFail();
        $goods = array_reduce(self::lines($delivery), fn (string $carry, array $line) => bcadd($carry, $line['amount'], 2), '0');
        $percent = bcadd((string) $order->discount_percent, '0', 2);
        $discount = SaveSalesOrderDraftAction::discountAmount($goods, $percent);
        $charges = self::chargesBilledElsewhere($order->id, $exceptInvoiceId) ? '0' : $order->chargesTotal();
        $subtotal = bcadd(bcsub($goods, $discount, 2), $charges, 2);
        $rate = is_numeric($taxRate) && bccomp($taxRate, '0', 2) > 0 ? bcadd($taxRate, '0', 2) : '0';
        $tax = bcadd(bcdiv(bcmul($subtotal, $rate, 6), '100', 6), '0.005', 2);

        return [
            'goods_amount' => $goods,
            'discount_percent' => $percent,
            'discount_amount' => $discount,
            'charges_amount' => $charges,
            'subtotal' => $subtotal,
            'tax_rate' => $rate,
            'tax_amount' => $tax,
            'total_amount' => bcadd($subtotal, $tax, 2),
        ];
    }
}
