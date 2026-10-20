<?php

namespace App\Actions\Purchasing;

use App\Models\PurchaseOrderCharge;

class UpdatePurchaseOrderChargeAction
{
    public function handle(
        PurchaseOrderCharge $charge,
        string $description,
        string $amount,
        bool $includeInCost = true,
    ): PurchaseOrderCharge {
        $charge->purchaseOrder->ensureIsEditable();

        $charge->description = $description;
        $charge->amount = $amount;
        $charge->include_in_cost = $includeInCost;
        $charge->save();

        $charge->purchaseOrder->recalculateTotal();

        return $charge;
    }
}
