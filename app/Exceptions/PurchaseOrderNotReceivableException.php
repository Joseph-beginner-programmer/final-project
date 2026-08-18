<?php

namespace App\Exceptions;

use App\Models\PurchaseOrder;
use RuntimeException;

class PurchaseOrderNotReceivableException extends RuntimeException
{
    public function __construct(
        public readonly PurchaseOrder $purchaseOrder
    ) {
        parent::__construct(
            "Purchase order #{$purchaseOrder->id} is [{$purchaseOrder->status->value}] and cannot received items."
        );
    }

    public function userMessage(): string 
    {
        return "Purchase Order cannot received items";
    }
}