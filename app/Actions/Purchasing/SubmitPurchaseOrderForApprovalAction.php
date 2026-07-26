<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\PurchaseOrderHaveNoItemException;
use App\Exceptions\PurchaseOrderItemQuantityNotValidException;
use App\Models\PurchaseOrder;

class SubmitPurchaseOrderForApprovalAction
{
    public function handle(PurchaseOrder $po): PurchaseOrder {
        $items = $po->items;
        if($items->isEmpty()) {
            throw new PurchaseOrderHaveNoItemException($po->id);
        }

        foreach($items as $item) {
            if(bccomp((string) $item->quantity_ordered, "0", 2) <= 0) {
                throw new PurchaseOrderItemQuantityNotValidException($po->id, $item);
            }
        }

        $po->transitionTo(PurchaseOrderStatus::PendingApproval);
        return $po;
    } 
}