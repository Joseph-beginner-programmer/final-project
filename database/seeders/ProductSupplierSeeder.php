<?php

namespace Database\Seeders;

use App\Models\ProductSupplier;
use Illuminate\Database\Seeder;

class ProductSupplierSeeder extends Seeder
{
    public function run(): void
    {
        $product_suppliers = [
            [
                'product_id' => '1',
                'supplier_id' => '1',
                'price' => 30000
            ],
            [
                'product_id' => '2',
                'supplier_id' => '1',
                'price' => 25000
            ],
            [
                'product_id' => '3',
                'supplier_id' => '2',
                'price' => 27000
            ],
            [
                'product_id' => '4',
                'supplier_id' => '2',
                'price' => 32000
            ],
            [
                'product_id' => '5',
                'supplier_id' => '3',
                'price' => 26000
            ],
            [
                'product_id' => '6',
                'supplier_id' => '3',
                'price' => 33000
            ],
        ];

        foreach($product_suppliers as $product_supplier) {
            ProductSupplier::create($product_supplier);
        }       
    }
}
