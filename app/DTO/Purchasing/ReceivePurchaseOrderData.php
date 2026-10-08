<?php

namespace App\DTO\Purchasing;

class ReceivePurchaseOrderData
{
    public function __construct(
        public readonly int $purchaseOrderItemId,
        public readonly string $quantityReceived,
        public readonly int $receivedBy,
        public readonly ?int $purchaseOrderReceiptBatchId
    ) {
        
    }
}