<?php

namespace App\Exceptions;

use App\Models\SalesOrder;
use RuntimeException;

class SalesOrderNotEditableException extends RuntimeException
{
    public function __construct(
        public readonly SalesOrder $salesOrder,
    ) {
        parent::__construct("Sales order {$salesOrder->so_number} is {$salesOrder->status->value}, not draft.");
    }

    public function userMessage(): string
    {
        return __('Only a draft sales order can be changed.');
    }
}
