<?php

namespace App\DTO\Employee;

use App\Enums\EmployeeStatus;

class EmployeeData
{
    public function __construct(
        public readonly string $name,
        public readonly string $hireDate,
        public readonly EmployeeStatus $status = EmployeeStatus::Active,
    ) {}
}
