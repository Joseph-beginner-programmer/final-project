<?php

namespace App\Exceptions;

use App\Models\WorkOrder;
use RuntimeException;

class WorkOrderNotIssuableException extends RuntimeException
{
    public function __construct(
        public readonly WorkOrder $workOrder,
    ) {
        parent::__construct("Work order {$workOrder->wo_number} is {$workOrder->status->value}; materials can only be issued while released or in progress.");
    }

    public function userMessage(): string
    {
        return __('Materials can only be issued to a released or in-progress work order.');
    }
}
