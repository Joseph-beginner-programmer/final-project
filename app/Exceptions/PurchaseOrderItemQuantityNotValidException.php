<?php

namespace App\Exceptions;

use App\Models\PurchaseOrderItem;
use RuntimeException;

class PurchaseOrderItemQuantityNotValidException extends RuntimeException {
    public function __construct(
        public readonly int $purchaseOrderId,
        public readonly PurchaseOrderItem $poItem
    ) {
        parent::__construct(
            "Item with product id #{$poItem->product_id} in purchase order #{$purchaseOrderId} have quantity of 0"
        );
    }

    public function userMessage(): string
    {
        return __('Each item quantity must be greater than 0.');
    }
}