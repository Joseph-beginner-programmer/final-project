<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class ProductSupplierSeeder extends Seeder
{
    public function run(): void
    {
        $supplierIds = Supplier::orderBy('id')->pluck('id')->all();
        $rawMaterialIds = Product::where('type', 'raw_material')->orderBy('id')->pluck('id')->all();

        foreach ($rawMaterialIds as $index => $productId) {
            ProductSupplier::create([
                'product_id' => $productId,
                'supplier_id' => $supplierIds[$index % count($supplierIds)],
                'price' => 10000,
            ]);
        }
    }
}
