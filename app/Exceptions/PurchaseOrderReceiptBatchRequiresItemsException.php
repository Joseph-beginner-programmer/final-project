<?php

namespace App\Exceptions;

use RuntimeException;

class PurchaseOrderReceiptBatchRequiresItemsException extends RuntimeException {
    public function __construct(
        public readonly ?int $purchaseOrderId = null,
    ) {
        parent::__construct(
            "Purchase Order #{$purchaseOrderId} receipt batch has no items with a quantity greater than zero"
        );
    }

    public function userMessage(): string
    {
        return __('At least one item must have a quantity greater than 0 to submit this receipt.');
    }
}
