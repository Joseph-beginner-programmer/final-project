<?php

namespace App\Actions\Purchasing;

use App\Models\PurchaseOrder;

class UpdatePurchaseOrderAction
{
    public function handle(
        PurchaseOrder $po,
        int $supplierId,
        string $orderDate,
        ?string $expectedDeliveryDate,
    ): PurchaseOrder {
        $po->ensureIsEditable();

        $po->supplier_id = $supplierId;
        $po->order_date = $orderDate;
        $po->expected_delivery_date = $expectedDeliveryDate;
        $po->save();

        return $po;
    }
}
