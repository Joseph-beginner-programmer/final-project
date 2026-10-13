<?php

namespace App\Actions\Sales;

use App\DTO\Sales\SalesOrderData;
use App\Enums\ProductType;
use App\Enums\SalesOrderStatus;
use App\Exceptions\InvalidSalesOrderException;
use App\Models\Product;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveSalesOrderDraftAction
{
    public function handle(SalesOrderData $data, int $userId, ?SalesOrder $order = null): SalesOrder
    {
        $order?->ensureIsDraft();

        $lines = array_values(array_filter($data->lines, fn (array $line) => bccomp($line['quantity'] ?: '0', '0', 2) > 0));
        if ($lines === []) {
            throw new InvalidSalesOrderException(InvalidSalesOrderException::NO_LINES);
        }

        foreach ($lines as $line) {
            $product = Product::findOrFail($line['productId']);
            if ($product->type !== ProductType::FinishedGoods) {
                throw new InvalidSalesOrderException(InvalidSalesOrderException::NOT_SELLABLE, $product->product_name);
            }
        }

        $subtotal = array_reduce($lines, fn (string $carry, array $line) => bcadd($carry, bcmul($line['quantity'], $line['unitPrice'], 2), 2), '0');
        $percent = $data->discountPercent ?: '0';
        if (bccomp($percent, '0', 2) < 0 || bccomp($percent, '100', 2) > 0) {
            throw new InvalidSalesOrderException(InvalidSalesOrderException::DISCOUNT_TOO_HIGH);
        }
        $discount = self::discountAmount($subtotal, $percent);

        $charges = array_values(array_filter($data->charges, fn (array $charge) => filled($charge['description']) && bccomp($charge['amount'] ?: '0', '0', 2) > 0));

        return DB::transaction(function () use ($data, $userId, $order, $lines, $charges, $subtotal, $percent, $discount) {
            $header = [
                'customer_id' => $data->customerId,
                'salesperson_id' => $data->salespersonId,
                'order_date' => $data->orderDate,
                'expected_delivery_date' => $data->expectedDeliveryDate ?: null,
                'delivery_address' => filled($data->deliveryAddress) ? $data->deliveryAddress : null,
                'contact_person' => filled($data->contactPerson) ? $data->contactPerson : null,
                'contact_phone' => filled($data->contactPhone) ? $data->contactPhone : null,
                'payment_terms' => $data->paymentTerms,
                'discount_percent' => $percent,
                'notes' => filled($data->notes) ? $data->notes : null,
            ];

            if (! $order) {
                $order = new SalesOrder($header + ['created_by' => $userId]);
                $order->status = SalesOrderStatus::Draft;
                $order->so_number = (string) Str::uuid();
                $order->save();

                $order->so_number = sprintf('SO-%s-%06d', $order->created_at->format('Y'), $order->id);
                $order->save();
            } else {
                $order->update($header);
            }

            $order->items()->delete();
            foreach ($lines as $line) {
                $item = $order->items()->make([
                    'product_id' => $line['productId'],
                    'quantity_ordered' => $line['quantity'],
                    'unit_price' => $line['unitPrice'],
                ]);
                $item->subtotal = bcmul($line['quantity'], $line['unitPrice'], 2);
                $item->save();
            }

            $order->charges()->delete();
            $chargesTotal = '0';
            foreach ($charges as $charge) {
                $order->charges()->create(['description' => $charge['description'], 'amount' => $charge['amount']]);
                $chargesTotal = bcadd($chargesTotal, $charge['amount'], 2);
            }

            $order->discount_amount = $discount;
            $order->total_amount = bcadd(bcsub($subtotal, $discount, 2), $chargesTotal, 2);
            $order->save();

            return $order->load(['items', 'charges']);
        });
    }

    public static function discountAmount(string $subtotal, string $percent): string
    {
        return bcdiv(bcmul($subtotal, $percent, 4), '100', 2);
    }
}
