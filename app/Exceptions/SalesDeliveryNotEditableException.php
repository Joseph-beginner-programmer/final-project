<?php

namespace App\Exceptions;

use App\Models\SalesDelivery;
use RuntimeException;

class SalesDeliveryNotEditableException extends RuntimeException
{
    public function __construct(
        public readonly SalesDelivery $delivery,
    ) {
        parent::__construct("Sales delivery {$delivery->delivery_number} is {$delivery->status->value}, not draft.");
    }

    public function userMessage(): string
    {
        return __('Only a draft delivery can be changed.');
    }
}
