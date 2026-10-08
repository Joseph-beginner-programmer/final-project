<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidPlannedDateRangeException extends RuntimeException
{
    public function __construct(
        public readonly string $plannedStartDate,
        public readonly string $plannedEndDate,
    ) {
        parent::__construct(
            "Planned end date [{$plannedEndDate}] is before planned start date [{$plannedStartDate}]."
        );
    }

    public function userMessage(): string
    {
        return __('The planned end date cannot be before the planned start date.');
    }
}
