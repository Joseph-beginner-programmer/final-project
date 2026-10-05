<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidDeliveryDateException extends RuntimeException
{
    public function __construct(
        public readonly string $expectedDeliveryDate,
        public readonly string $orderDate,
    ) {
        parent::__construct(
            "Expected delivery date [{$expectedDeliveryDate}] cannot be before order date [{$orderDate}]."
        );
    }

    public function userMessage(): string
    {
        return __('Expected delivery date cannot be before the order date.');
    }
}
