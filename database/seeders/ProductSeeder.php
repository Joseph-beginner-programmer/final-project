<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'product_code' => 'RAW-001',
                'product_name' => 'PP Merah',
                'unit_of_measure' => 'Kg',
                'type' => 'raw_material',
            ],
            [
                'product_code' => 'RAW-002',
                'product_name' => 'PP Biru',
                'unit_of_measure' => 'Kg',
                'type' => 'raw_material',
            ],
            [
                'product_code' => 'RAW-003',
                'product_name' => 'PP Hijau',
                'unit_of_measure' => 'Kg',
                'type' => 'raw_material',
            ],
            [
                'product_code' => 'RAW-004',
                'product_name' => 'PP Putih',
                'unit_of_measure' => 'Kg',
                'type' => 'raw_material',
            ],
            [
                'product_code' => 'RAW-005',
                'product_name' => 'PP Hitam',
                'unit_of_measure' => 'Kg',
                'type' => 'raw_material',
            ],
            [
                'product_code' => 'RAW-006',
                'product_name' => 'PP Nilek',
                'unit_of_measure' => 'Kg',
                'type' => 'raw_material',
            ],
        ];

        foreach($products as $product) {
            Product::create($product);
        }       
    }
}
