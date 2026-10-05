<?php

namespace App\Exceptions;

use RuntimeException;

class PurchaseOrderReceiptBatchRequiresAttachmentException extends RuntimeException {
    public function __construct(
        public readonly ?int $purchaseOrderId = null,
    ) {
        parent::__construct(
            "Purchase Order #{$purchaseOrderId} receipt batch has no attachment"
        );
    }

    public function userMessage(): string
    {
        return __('An attachment (delivery note, photo, etc.) is required to submit this receipt.');
    }
}
