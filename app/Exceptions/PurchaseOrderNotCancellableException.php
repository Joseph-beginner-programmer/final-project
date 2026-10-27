<?php

namespace App\Exceptions;

use App\Models\PurchaseOrder;
use RuntimeException;

class PurchaseOrderNotCancellableException extends RuntimeException
{
    public function __construct(public readonly PurchaseOrder $purchaseOrder)
    {
        parent::__construct("Purchase order {$purchaseOrder->po_number} already has receipts and cannot be cancelled.");
    }

    public function userMessage(): string
    {
        return __(':number already has goods received, so it cannot be cancelled. Close the purchase order instead.', ['number' => $this->purchaseOrder->po_number]);
    }
}
