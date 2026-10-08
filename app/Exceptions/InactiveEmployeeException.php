<?php

namespace App\Exceptions;

use App\Models\Employee;
use RuntimeException;

class InactiveEmployeeException extends RuntimeException
{
    public function __construct(
        public readonly Employee $employee,
    ) {
        parent::__construct("Employee #{$employee->id} ({$employee->employee_code}) is inactive and cannot be assigned.");
    }

    public function userMessage(): string
    {
        return __(':name is inactive and cannot be assigned to a work order.', ['name' => $this->employee->name]);
    }
}
