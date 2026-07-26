<?php

namespace App\Exceptions;

use RuntimeException;

class PurchaseOrderHaveNoItemException extends RuntimeException {
    public function __construct(
        public readonly ?int $purchaseOrderId = null,
    ) {
        parent::__construct( 
            "Purchase Order #{$purchaseOrderId} has no items"
        );
    }
    
    public function userMessage(): string
    {
        return __('This purchase order has no items. Please select an item.');
    }
}