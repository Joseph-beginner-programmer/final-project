<?php

namespace App\Actions\Purchasing;

use App\Models\PurchaseOrderItem;

class RemovePurchaseOrderItemAction
{
    public function handle(PurchaseOrderItem $item): void
    {
        $po = $item->purchaseOrder;

        $po->ensureIsEditable();

        $item->delete(); 

        $po->recalculateTotal();
    }
}
