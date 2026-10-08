<?php

namespace App\Actions\Employee;

use App\DTO\Employee\EmployeeData;
use App\Models\Employee;

class UpdateEmployeeAction
{
    public function handle(Employee $employee, EmployeeData $data): Employee
    {
        $employee->update([
            'name' => $data->name,
            'hire_date' => $data->hireDate,
            'status' => $data->status,
        ]);

        return $employee;
    }
}
