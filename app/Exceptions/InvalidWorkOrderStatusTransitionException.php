<?php

namespace App\Exceptions;

use App\Enums\WorkOrderStatus;
use RuntimeException;

class InvalidWorkOrderStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly WorkOrderStatus $from,
        public readonly WorkOrderStatus $to,
        public readonly ?int $workOrderId = null,
    ) {
        parent::__construct(
            "Work order #{$workOrderId} cannot transition from [{$from->value}] to [{$to->value}]."
        );
    }

    public function userMessage(): string
    {
        return __('This work order was updated by someone else. Please refresh the page and try again.');
    }
}
