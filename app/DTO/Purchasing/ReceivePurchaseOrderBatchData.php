<?php

namespace App\DTO\Purchasing;

class ReceivePurchaseOrderBatchData
{
    public function __construct(
        public readonly int $purchaseOrderId,
        public readonly int $receivedBy,
        public readonly array $items,
        public readonly ?string $attachmentPath,
    ) {}
}