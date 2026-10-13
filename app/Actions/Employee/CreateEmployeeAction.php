<?php

namespace App\Actions\Employee;

use App\DTO\Employee\EmployeeData;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateEmployeeAction
{
    public function handle(EmployeeData $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $employee = new Employee([
                'name' => $data->name,
                'hire_date' => $data->hireDate,
                'status' => $data->status,
            ]);

            $employee->employee_code = (string) Str::uuid();
            $employee->save();

            $employee->employee_code = sprintf('EMP-%06d', $employee->id);
            $employee->save();

            return $employee;
        });
    }
}
