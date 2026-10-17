<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Model::unguarded(fn () => $this->call([
            SupplierSeeder::class,
            ProductSeeder::class,
            ProductSupplierSeeder::class,
            ProductionSeeder::class,
            EmployeeSeeder::class,
        ]));
    }
}
