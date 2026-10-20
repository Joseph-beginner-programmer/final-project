<?php

namespace App\Actions\Purchasing;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCharge;

class AddPurchaseOrderChargeAction
{
    public function handle(
        PurchaseOrder $po,
        string $description,
        string $amount,
        bool $includeInCost = true,
    ): PurchaseOrderCharge {
        $po->ensureIsEditable();

        $charge = new PurchaseOrderCharge([
            'purchase_order_id' => $po->id,
            'description' => $description,
            'amount' => $amount,
            'include_in_cost' => $includeInCost,
        ]);
        $charge->save();

        $po->recalculateTotal();

        return $charge;
    }
}
