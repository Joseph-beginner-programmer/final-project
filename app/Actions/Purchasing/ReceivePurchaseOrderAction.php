<?php 

namespace App\Actions\Purchasing;

use App\Actions\Inventory\CreateStockMovementAction;
use App\DTO\Inventory\CreateStockMovementData;
use App\DTO\Purchasing\ReceivePurchaseOrderData;
use App\Enums\Direction;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidReceiptQuantityException;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use Illuminate\Support\Facades\DB;

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
                'received_by' => $data->receivedBy,
                'received_at' => now(),
                'receipt_condition' => $data->receiptCondition
            ]);

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