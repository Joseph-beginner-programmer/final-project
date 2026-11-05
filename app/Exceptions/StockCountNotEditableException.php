<?php

namespace App\Exceptions;

use App\Models\StockCount;
use RuntimeException;

class StockCountNotEditableException extends RuntimeException
{
    public function __construct(
        public readonly StockCount $stockCount,
    ) {
        parent::__construct("Stock count {$stockCount->count_number} is {$stockCount->status->value}, not draft.");
    }

    public function userMessage(): string
    {
        return __('Only a draft stock count can be changed.');
    }
}
