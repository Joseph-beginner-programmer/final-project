<?php

namespace App\Exceptions;

use RuntimeException;

class PlannedStartDateInPastException extends RuntimeException
{
    public function __construct(
        public readonly string $plannedStartDate,
        public readonly string $today,
    ) {
        parent::__construct("Planned start date [{$plannedStartDate}] is before today [{$today}].");
    }

    public function userMessage(): string
    {
        return __('The planned start date cannot be in the past.');
    }
}
