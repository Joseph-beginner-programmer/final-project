<?php

namespace App\Actions\Warehouse;

use App\Actions\Inventory\DrawStockFifoAction;
use App\Enums\DocumentStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\SalesDelivery;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

class PostSalesDeliveryAction
{
    public function __construct(
        private readonly DrawStockFifoAction $fifo,
    ) {}

    public function handle(SalesDelivery $delivery, int $userId): SalesDelivery
    {
        return DB::transaction(function () use ($delivery, $userId) {
            $delivery = SalesDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $delivery->ensureIsDraft();

            $order = SalesOrder::whereKey($delivery->sales_order_id)->lockForUpdate()->firstOrFail();
            $quantities = $delivery->items()->pluck('quantity', 'sales_order_item_id')->map(fn ($q) => (string) $q)->all();
            SaveSalesDeliveryDraftAction::checkDeliverable($order, $quantities, $delivery);

            $items = $delivery->items()->with(['product', 'salesOrderItem'])->get();

            $lotsByProduct = [];
            foreach ($items as $item) {
                $lots = $this->fifo->lockAvailableLots($item->product_id);
                $available = $this->fifo->available($lots);

                if (bccomp($available, (string) $item->quantity, 2) < 0) {
                    throw new InsufficientStockException($item->product, (string) $item->quantity, $available);
                }

                $lotsByProduct[$item->product_id] = $lots;
            }

            foreach ($items as $item) {
                $item->cogs_total = $this->fifo->draw(
                    $lotsByProduct[$item->product_id],
                    $item->product_id,
                    (string) $item->quantity,
                    StockMovementType::SalesShipment,
                    $userId,
                    'sales_delivery_item',
                    $item->id,
                );
                $item->save();

                $orderItem = $item->salesOrderItem;
                $orderItem->quantity_delivered = bcadd((string) $orderItem->quantity_delivered, (string) $item->quantity, 2);
                $orderItem->save();
            }

            $delivery->delivered_by = $userId;
            $delivery->delivered_at = now();
            $delivery->transitionTo(DocumentStatus::Posted);

            $order->syncDeliveryStatus();

            return $delivery->load('items.product');
        });
    }
}
