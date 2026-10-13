<?php

namespace App\Exceptions;

use App\Enums\SalesOrderStatus;
use RuntimeException;

class InvalidSalesOrderStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly SalesOrderStatus $from,
        public readonly SalesOrderStatus $to,
        public readonly string $soNumber = '',
    ) {
        parent::__construct("Sales order {$soNumber} cannot transition from [{$from->value}] to [{$to->value}].");
    }

    public function userMessage(): string
    {
        return __('This sales order was updated by someone else. Please refresh the page and try again.');
    }
}
