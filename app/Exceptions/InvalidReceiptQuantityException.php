<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidReceiptQuantityException extends RuntimeException {
    public function __construct(
        public readonly string $quantityReceived
    ) {
        return parent::__construct( "Received quantity must be greater than zero. Received: $quantityReceived.");
    }

    public function userMessage(): string {
        return "The received quantity must be greater than zero.";
    }
}