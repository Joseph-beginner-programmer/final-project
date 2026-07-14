<?php

namespace App\Exceptions;

use App\Enums\PurchaseOrderStatus;
use RuntimeException;

class InvalidStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly PurchaseOrderStatus $from,
        public readonly PurchaseOrderStatus $to,
        public readonly ?int $purchaseOrderId = null,
    ) {
        parent::__construct(
            "Purchase order #{$purchaseOrderId} cannot transition from [{$from->value}] to [{$to->value}]."
        );
    }

    public function userMessage(): string
    {
        return 'This purchase order was updated by someone else. Please refresh the page and try again.';
    }
}