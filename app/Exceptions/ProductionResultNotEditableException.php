<?php

namespace App\Exceptions;

use App\Models\ProductionResult;
use RuntimeException;

class ProductionResultNotEditableException extends RuntimeException
{
    public function __construct(
        public readonly ProductionResult $result,
    ) {
        parent::__construct("Production result {$result->result_number} is {$result->status->value}, not draft.");
    }

    public function userMessage(): string
    {
        return __('Only a draft production result can be changed.');
    }
}
