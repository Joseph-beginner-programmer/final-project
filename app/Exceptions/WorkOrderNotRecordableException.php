<?php

namespace App\Exceptions;

use App\Models\WorkOrder;
use RuntimeException;

/**
 * A result can only be recorded for a WO that is in progress, and only once (one result per WO).
 */
class WorkOrderNotRecordableException extends RuntimeException
{
    public const NOT_IN_PROGRESS = 'not_in_progress';
    public const ALREADY_RECORDED = 'already_recorded';

    public function __construct(
        public readonly WorkOrder $workOrder,
        public readonly string $reason,
    ) {
        parent::__construct("Cannot record a result for work order {$workOrder->wo_number} [{$reason}].");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::ALREADY_RECORDED => __('This work order already has a production result.'),
            default => __('A production result can only be recorded for a work order that is in progress.'),
        };
    }
}
