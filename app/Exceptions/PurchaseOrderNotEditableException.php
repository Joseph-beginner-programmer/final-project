<?php

namespace App\Exceptions;

use App\Models\PurchaseOrder;
use RuntimeException;

class PurchaseOrderNotEditableException extends RuntimeException
{
    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
    ) {
        parent::__construct(
            "Purchase order #{$purchaseOrder->id} is [{$purchaseOrder->status->value}] and can no longer be edited."
        );
    }

    public function userMessage(): string
    {
        return __('This purchase order can no longer be edited because it is no longer a draft.');
    }
}
