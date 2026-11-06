<?php

namespace App\Actions\Purchasing;

use App\Models\DocumentCharge;
use App\Models\PurchaseOrder;

class RemovePurchaseOrderChargeAction
{
    public function handle(DocumentCharge $charge): void
    {
        /** @var PurchaseOrder $po */
        $po = $charge->chargeable;

        $po->ensureIsEditable();

        $charge->delete();

        $po->recalculateTotal();
    }
}
