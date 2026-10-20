<?php

namespace App\Actions\Purchasing;

use App\Models\PurchaseOrderCharge;

class RemovePurchaseOrderChargeAction
{
    public function handle(PurchaseOrderCharge $charge): void
    {
        $po = $charge->purchaseOrder;

        $po->ensureIsEditable();

        $charge->delete();

        $po->recalculateTotal();
    }
}
