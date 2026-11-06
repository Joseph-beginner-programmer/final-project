<?php

namespace App\Actions\Purchasing;

use App\Models\DocumentCharge;
use App\Models\PurchaseOrder;

class AddPurchaseOrderChargeAction
{
    public function handle(
        PurchaseOrder $po,
        string $description,
        string $amount,
        bool $includeInCost = true,
    ): DocumentCharge {
        $po->ensureIsEditable();

        $charge = $po->charges()->create([
            'description' => $description,
            'amount' => $amount,
            'include_in_cost' => $includeInCost,
        ]);

        $po->recalculateTotal();

        return $charge;
    }
}
