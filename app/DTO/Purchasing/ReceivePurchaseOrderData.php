<?php

namespace App\DTO\Purchasing;

use App\Enums\PurchaseOrderItemReceiptCondition;

class ReceivePurchaseOrderData
{
    public function __construct(
        public readonly int $purchaseOrderItemId,
        public readonly string $quantityReceived,
        public readonly int $receivedBy,
        public readonly PurchaseOrderItemReceiptCondition $receiptCondition
    ) {
        
    }
}