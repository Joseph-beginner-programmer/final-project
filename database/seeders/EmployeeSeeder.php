<?php

namespace Database\Seeders;

use App\Actions\Employee\CreateEmployeeAction;
use App\Actions\Employee\SetLaborRateAction;
use App\DTO\Employee\EmployeeData;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dev floor workers with an hourly rate each. Goes through the real Actions so codes and
 * rate history are built exactly as the app would. Skips anyone already present by name.
 */
class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $creator = User::query()->orderBy('id')->firstOrFail();

        $employees = [
            ['Budi Santoso', '2023-02-01', '25000'],
            ['Siti Rahayu', '2023-06-15', '25000'],
            ['Agus Wijaya', '2024-01-08', '22000'],
            ['Dewi Lestari', '2022-11-20', '23000'],
            ['Rudi Hartono', '2021-03-01', '26000'],
        ];

        foreach ($employees as [$name, $hireDate, $rate]) {
            if (Employee::where('name', $name)->exists()) {
                continue;
            }

            $employee = app(CreateEmployeeAction::class)->handle(new EmployeeData($name, $hireDate));

            app(SetLaborRateAction::class)->handle($employee, $rate, '2026-01-01', $creator->id);
        }
    }
}
