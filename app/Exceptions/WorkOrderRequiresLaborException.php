<?php

namespace App\Exceptions;

use RuntimeException;

class WorkOrderRequiresLaborException extends RuntimeException
{
    public function __construct(
        public readonly ?int $workOrderId = null,
    ) {
        parent::__construct(
            $workOrderId ? "Work order #{$workOrderId} has no workers allocated." : 'New work order has no workers allocated.'
        );
    }

    public function userMessage(): string
    {
        return __('A work order needs at least one assigned worker.');
    }
}
