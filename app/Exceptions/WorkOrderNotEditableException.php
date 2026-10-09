<?php

namespace App\Exceptions;

use App\Models\WorkOrder;
use RuntimeException;

class WorkOrderNotEditableException extends RuntimeException
{
    public function __construct(
        public readonly WorkOrder $workOrder,
    ) {
        parent::__construct("Work order {$workOrder->wo_number} is {$workOrder->status->value}, not draft.");
    }

    public function userMessage(): string
    {
        return __('Only a draft work order can be changed.');
    }
}
