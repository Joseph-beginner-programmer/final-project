<?php

namespace App\Actions\Purchasing;

use App\Models\DocumentCharge;
use App\Models\PurchaseOrder;

class UpdatePurchaseOrderChargeAction
{
    public function handle(
        DocumentCharge $charge,
        string $description,
        string $amount,
        bool $includeInCost = true,
    ): DocumentCharge {
        /** @var PurchaseOrder $po */
        $po = $charge->chargeable;

        $po->ensureIsEditable();

        $charge->description = $description;
        $charge->amount = $amount;
        $charge->include_in_cost = $includeInCost;
        $charge->save();

        $po->recalculateTotal();

        return $charge;
    }
}
