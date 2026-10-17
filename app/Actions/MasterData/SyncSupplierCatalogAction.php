<?php

namespace App\Actions\MasterData;

use App\Enums\ProductType;
use App\Exceptions\InvalidMasterDataException;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

class SyncSupplierCatalogAction
{
    /**
     * @param  array<int, string>  $prices  product id => price
     */
    public function handle(Supplier $supplier, array $prices): Supplier
    {
        foreach (array_keys($prices) as $productId) {
            $product = Product::findOrFail($productId);
            if ($product->type !== ProductType::RawMaterial) {
                throw new InvalidMasterDataException(InvalidMasterDataException::CATALOG_NOT_RAW, $product->product_name);
            }
        }

        DB::transaction(fn () => $supplier->products()->sync(
            collect($prices)->mapWithKeys(fn (string $price, int $productId) => [$productId => ['price' => $price]])->all()
        ));

        return $supplier->load('products');
    }
}
