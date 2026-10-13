<?php

namespace App\Exceptions;

use App\Models\ProductionReceipt;
use RuntimeException;

class ProductionReceiptNotEditableException extends RuntimeException
{
    public function __construct(
        public readonly ProductionReceipt $receipt,
    ) {
        parent::__construct("Production receipt {$receipt->receipt_number} is {$receipt->status->value}, not draft.");
    }

    public function userMessage(): string
    {
        return __('Only a draft production receipt can be changed.');
    }
}
