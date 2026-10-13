<?php

namespace App\Actions\Sales;

use App\Enums\DocumentStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\InvalidSalesOrderException;
use App\Models\SalesDelivery;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

class CancelSalesOrderAction
{
    public function handle(SalesOrder $order, int $userId, string $reason): SalesOrder
    {
        return DB::transaction(function () use ($order, $userId, $reason) {
            $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->hasDeliveries()) {
                throw new InvalidSalesOrderException(InvalidSalesOrderException::ALREADY_DELIVERED);
            }

            $order->cancelled_by = $userId;
            $order->cancelled_at = now();
            $order->cancel_reason = $reason;
            $order->transitionTo(SalesOrderStatus::Cancelled);

            SalesDelivery::where('sales_order_id', $order->id)
                ->where('status', DocumentStatus::Draft->value)
                ->lockForUpdate()
                ->get()
                ->each(fn (SalesDelivery $delivery) => $delivery->transitionTo(DocumentStatus::Cancelled));

            return $order;
        });
    }
}
