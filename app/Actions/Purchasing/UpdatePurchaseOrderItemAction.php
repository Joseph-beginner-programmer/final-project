<?php

namespace App\Actions\Purchasing;

use App\Models\PurchaseOrderItem;

class UpdatePurchaseOrderItemAction
{
    public function handle(
        PurchaseOrderItem $item,
        string $quantityOrdered, 
        string $unitPrice,
    ): PurchaseOrderItem {
        $item->purchaseOrder->ensureIsEditable();

        $item->quantity_ordered = $quantityOrdered;
        $item->unit_price = $unitPrice;
        $item->syncSubtotal();
        $item->save();

        $item->purchaseOrder->recalculateTotal();

        return $item;
    }
}
