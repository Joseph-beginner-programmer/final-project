<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['product_code' => 'RAW-001', 'product_name' => 'PP Recycle CPP Polos, Natural', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-002', 'product_name' => 'Pewarna Biru DC 062.23', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-003', 'product_name' => 'Pewarna Pink DC 062.23', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-004', 'product_name' => 'Pewarna Hijau DC 062.23', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-005', 'product_name' => 'Pewarna Gold DC 062.23', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-006', 'product_name' => 'Pewarna Merah DC 062.23', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-007', 'product_name' => 'Pewarna White DCA 1064', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-008', 'product_name' => 'Pewarna Hitam DCA 1064', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-009', 'product_name' => 'PP Biru', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-010', 'product_name' => 'PP Pink', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-011', 'product_name' => 'PP Hijau', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-012', 'product_name' => 'PP Gold', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-013', 'product_name' => 'PP Merah', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-014', 'product_name' => 'PP Putih', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-015', 'product_name' => 'Mastebath Blue DCS 5504', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-016', 'product_name' => 'Mastebath Pink DCS 5504', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-017', 'product_name' => 'Mastebath Green DCS 5504', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-018', 'product_name' => 'Mastebath Gold DCS 5504', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-019', 'product_name' => 'Mastebath Red DCS 5504', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-020', 'product_name' => 'Mastebath White DCS 5504', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-021', 'product_name' => 'TPE Everpa TC 40 NC', 'unit_of_measure' => 'Kg', 'type' => 'raw_material'],
            ['product_code' => 'RAW-022', 'product_name' => 'Stiker Elephant Gold Vinyl Ukuran (9,5 x 2,7)', 'unit_of_measure' => 'Pcs', 'type' => 'raw_material'],
            ['product_code' => 'RAW-023', 'product_name' => 'Box IB HTC 777 (197X148X412)', 'unit_of_measure' => 'Pcs', 'type' => 'raw_material'],
            ['product_code' => 'RAW-024', 'product_name' => 'Stiker Bunga HTC 777 Atas (Thermal Transfer Printing)', 'unit_of_measure' => 'Pcs', 'type' => 'raw_material'],
            ['product_code' => 'RAW-025', 'product_name' => 'Isolasi Fragile 48 X 65', 'unit_of_measure' => 'Roll', 'type' => 'raw_material'],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}
