<?php 

namespace App\Actions\Purchasing;

use App\Actions\Inventory\CreateStockMovementAction;
use App\DTO\Inventory\CreateStockMovementData;
use App\DTO\Purchasing\ReceivePurchaseOrderData;
use App\Enums\Direction;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidReceiptQuantityException;
use App\Models\InventoryLot;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceivePurchaseOrderAction
{
    public function handle(ReceivePurchaseOrderData $data): PurchaseOrderReceipt {
        return DB::transaction(function () use ($data) {
            $poItem = PurchaseOrderItem::where('id', $data->purchaseOrderItemId)->lockForUpdate()->firstOrFail();

            // checks
            if(bccomp($data->quantityReceived, "0", 2) <= 0) throw new InvalidReceiptQuantityException($data->quantityReceived);
            $poItem->purchaseOrder->ensureCanReceive();

            $receipt = PurchaseOrderReceipt::create([
                'purchase_order_item_id' => $data->purchaseOrderItemId,
                'quantity_received' => $data->quantityReceived,
                'purchase_order_receipt_batch_id' => $data->purchaseOrderReceiptBatchId
            ]);

            $lot = InventoryLot::create([
                'lot_number' => (string) Str::uuid(), // placeholder; real number needs this row's own id, set below
                'product_id' => $poItem->product->id,
                'source_type' => 'purchase_order_receipt',
                'source_id' => $receipt->id,
                'quantity_initial' => $data->quantityReceived,
                'quantity_remaining' => $data->quantityReceived,
                'unit_cost' => $poItem->unit_price,
                'value_remaining' => bcmul($data->quantityReceived, (string) $poItem->unit_price, 2),
                'received_at' => now(),
            ]);
            $lot->lot_number = sprintf('LOT-%s-%06d', $lot->received_at->format('Y'), $lot->id);
            $lot->save();

            $poItem->syncQuantityReceived();
            $poItem->save();

            $allFullyReceived = $poItem->purchaseOrder->items()->get()->every(fn($i) => $i->isFullyReceived());

            $target = $allFullyReceived ? PurchaseOrderStatus::FullyReceived : PurchaseOrderStatus::PartiallyReceived;
            if($target !== $poItem->purchaseOrder->status) $poItem->purchaseOrder->transitionTo($target);

            app(CreateStockMovementAction::class)->handle(new CreateStockMovementData(
                $poItem->product->id,
                Direction::In,
                StockMovementType::PurchaseReceived,
                $data->quantityReceived,
                $data->receivedBy,
                $receipt->id,
                'purchase_order_receipt' 
            ));

            return $receipt;
        });
    }
}