<?php

namespace App\Actions\Warehouse;

use App\Enums\DocumentStatus;
use App\Exceptions\InvalidSalesDeliveryException;
use App\Models\SalesDelivery;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveSalesDeliveryDraftAction
{
    /**
     * @param  array<int, string>  $quantities  sales_order_item id => quantity to deliver now
     */
    public function handle(SalesOrder $order, array $quantities, ?string $notes, int $userId, ?SalesDelivery $delivery = null): SalesDelivery
    {
        $delivery?->ensureIsDraft();

        $lines = self::checkDeliverable($order, $quantities, $delivery);

        return DB::transaction(function () use ($order, $notes, $userId, $delivery, $lines) {
            if (! $delivery) {
                $delivery = new SalesDelivery([
                    'sales_order_id' => $order->id,
                    'notes' => filled($notes) ? $notes : null,
                    'created_by' => $userId,
                ]);
                $delivery->status = DocumentStatus::Draft;
                $delivery->delivery_number = (string) Str::uuid();
                $delivery->save();

                $delivery->delivery_number = sprintf('DO-%s-%06d', $delivery->created_at->format('Y'), $delivery->id);
                $delivery->save();
            } else {
                $delivery->update(['notes' => filled($notes) ? $notes : null]);
            }

            $delivery->items()->delete();
            foreach ($lines as [$item, $quantity]) {
                $delivery->items()->create([
                    'sales_order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $quantity,
                ]);
            }

            return $delivery->load('items');
        });
    }

    /**
     * @param  array<int, string>  $quantities
     * @return list<array{0: SalesOrderItem, 1: string}>
     */
    public static function checkDeliverable(SalesOrder $order, array $quantities, ?SalesDelivery $current = null): array
    {
        if (! $order->status->isDeliverable()) {
            throw new InvalidSalesDeliveryException(InvalidSalesDeliveryException::ORDER_NOT_DELIVERABLE);
        }

        $otherDraft = $order->deliveries()
            ->where('status', DocumentStatus::Draft->value)
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->first();
        if ($otherDraft) {
            throw new InvalidSalesDeliveryException(InvalidSalesDeliveryException::OPEN_DRAFT, $otherDraft->delivery_number);
        }

        $lines = [];
        foreach ($order->items()->with('product')->get() as $item) {
            $quantity = $quantities[$item->id] ?? '0';
            if (bccomp($quantity ?: '0', '0', 2) <= 0) {
                continue;
            }
            if (bccomp($quantity, $item->remainingQuantity(), 2) > 0) {
                throw new InvalidSalesDeliveryException(InvalidSalesDeliveryException::MORE_THAN_ORDERED, $item->product->product_name);
            }
            $lines[] = [$item, $quantity];
        }

        if ($lines === []) {
            throw new InvalidSalesDeliveryException(InvalidSalesDeliveryException::NO_LINES);
        }

        return $lines;
    }
}
