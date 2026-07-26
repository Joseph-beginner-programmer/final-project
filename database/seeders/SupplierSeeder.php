<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            [
                'supplier_code' => 'SUP-001',
                'supplier_name' => 'Supplier A',
                'contact_person' => 'Orang A',
                'phone' => '+628113091980',
                'email' => 'suppliera@gmail.com',
                'address' => 'Jalan Supplier A'
            ],
            [
                'supplier_code' => 'SUP-002',
                'supplier_name' => 'Supplier B',
                'contact_person' => 'Orang B',
                'phone' => '+628113091981',
                'email' => 'supplierb@gmail.com',
                'address' => 'Jalan Supplier B'
            ],
            [
                'supplier_code' => 'SUP-003',
                'supplier_name' => 'Supplier C',
                'contact_person' => 'Orang C',
                'phone' => '+628113091982',
                'email' => 'supplierc@gmail.com',
                'address' => 'Jalan Supplier C'
            ]
        ];

        foreach($suppliers as $supplier) {
            Supplier::create($supplier);
        }       
    }
}
