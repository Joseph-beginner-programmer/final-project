<?php

namespace App\Exceptions;

use App\Models\Employee;
use RuntimeException;

class MissingLaborRateException extends RuntimeException
{
    public function __construct(
        public readonly Employee $employee,
        public readonly string $date,
    ) {
        parent::__construct("Employee #{$employee->id} ({$employee->employee_code}) has no labor rate in effect on [{$date}].");
    }

    public function userMessage(): string
    {
        return __(':name has no hourly rate set yet. Ask Accounting to set one first.', ['name' => $this->employee->name]);
    }
}
