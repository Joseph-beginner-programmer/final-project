<?php

namespace App\Actions\Sales;

use App\Enums\DocumentStatus;
use App\Enums\ReturnCondition;
use App\Exceptions\InvalidSalesReturnException;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveSalesReturnDraftAction
{
    /**
     * @param  array<int, array{quantity: string, condition: string}>  $lines
     */
    public function handle(SalesDelivery $delivery, array $lines, string $returnDate, string $reason, int $userId, ?SalesReturn $return = null): SalesReturn
    {
        $return?->ensureIsDraft();

        if (blank(trim($reason))) {
            throw new InvalidSalesReturnException(InvalidSalesReturnException::REASON_REQUIRED);
        }
        self::checkDate($delivery, $returnDate);

        return DB::transaction(function () use ($delivery, $lines, $returnDate, $reason, $userId, $return) {
            SalesDelivery::whereKey($delivery->id)->lockForUpdate()->first();

            $otherDraft = SalesReturn::query()
                ->where('sales_delivery_id', $delivery->id)
                ->where('status', DocumentStatus::Draft->value)
                ->when($return, fn ($query) => $query->whereKeyNot($return->id))
                ->first();
            if ($otherDraft) {
                throw new InvalidSalesReturnException(InvalidSalesReturnException::OPEN_DRAFT, $otherDraft->return_number);
            }

            $checked = self::checkReturnable($delivery, $lines);
            $header = ['return_date' => $returnDate, 'reason' => trim($reason)];

            if (! $return) {
                $return = new SalesReturn($header + [
                    'sales_order_id' => $delivery->sales_order_id,
                    'sales_delivery_id' => $delivery->id,
                    'customer_id' => $delivery->salesOrder->customer_id,
                    'created_by' => $userId,
                ]);
                $return->status = DocumentStatus::Draft;
                $return->return_number = (string) Str::uuid();
                $return->save();
                $return->return_number = sprintf('RTJ-%s-%06d', $return->created_at->format('Y'), $return->id);
                $return->save();
            } else {
                $return->update($header);
            }

            $return->items()->delete();
            $total = '0';
            foreach ($checked as [$item, $quantity, $condition]) {
                $price = (string) $item->salesOrderItem->unit_price;
                $subtotal = bcadd(bcmul($quantity, $price, 6), '0.005', 2);
                $return->items()->create([
                    'sales_delivery_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $quantity,
                    'condition' => $condition,
                    'unit_price' => $price,
                    'subtotal' => $subtotal,
                ]);
                $total = bcadd($total, $subtotal, 2);
            }
            $return->total_amount = $total;
            $return->save();

            return $return->load('items');
        });
    }

    public static function checkDate(SalesDelivery $delivery, string $returnDate): void
    {
        if ($delivery->status !== DocumentStatus::Posted || ! $delivery->delivered_at) {
            throw new InvalidSalesReturnException(InvalidSalesReturnException::NOT_DELIVERED);
        }
        $date = Carbon::parse($returnDate)->startOfDay();
        if ($date->gt(now()->startOfDay())) {
            throw new InvalidSalesReturnException(InvalidSalesReturnException::FUTURE_DATE);
        }
        if ($date->lt($delivery->delivered_at->copy()->startOfDay())) {
            throw new InvalidSalesReturnException(InvalidSalesReturnException::BEFORE_DELIVERY);
        }
    }

    /**
     * @param  array<int, array{quantity: string, condition: string}>  $lines
     * @return list<array{0: SalesDeliveryItem, 1: string, 2: ReturnCondition}>
     */
    public static function checkReturnable(SalesDelivery $delivery, array $lines): array
    {
        $checked = [];
        foreach ($delivery->items()->with(['product', 'salesOrderItem'])->orderBy('id')->get() as $item) {
            $quantity = $lines[$item->id]['quantity'] ?? '0';
            if (! is_numeric($quantity) || bccomp((string) $quantity, '0', 2) <= 0) {
                continue;
            }
            $quantity = bcadd((string) $quantity, '0', 2);
            if (bccomp($quantity, $item->netQuantity(), 2) > 0) {
                throw new InvalidSalesReturnException(InvalidSalesReturnException::MORE_THAN_DELIVERED, $item->product->product_name);
            }
            $checked[] = [$item, $quantity, ReturnCondition::tryFrom($lines[$item->id]['condition'] ?? '') ?? ReturnCondition::Good];
        }

        if ($checked === []) {
            throw new InvalidSalesReturnException(InvalidSalesReturnException::NO_LINES);
        }

        return $checked;
    }
}
