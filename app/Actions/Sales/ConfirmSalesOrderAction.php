<?php

namespace App\Actions\Sales;

use App\Enums\SalesOrderStatus;
use App\Exceptions\InvalidSalesOrderException;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

class ConfirmSalesOrderAction
{
    public function handle(SalesOrder $order, int $userId): SalesOrder
    {
        return DB::transaction(function () use ($order, $userId) {
            $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->ensureIsDraft();

            if ($order->items()->doesntExist()) {
                throw new InvalidSalesOrderException(InvalidSalesOrderException::NO_LINES);
            }

            $order->confirmed_by = $userId;
            $order->confirmed_at = now();
            $order->transitionTo(SalesOrderStatus::Confirmed);

            return $order;
        });
    }
}
