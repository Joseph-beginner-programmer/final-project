<?php

namespace App\Actions\MasterData;

use App\Enums\ProductType;
use App\Exceptions\InvalidMasterDataException;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class SaveProductAction
{
    /**
     * @param  array{product_name: string, type: ProductType, unit_of_measure: string, safety_stock: string, reorder_point: string, selling_price: ?string, is_active: bool}  $fields
     */
    public function handle(array $fields, ?Product $product = null): Product
    {
        $type = $fields['type'];

        if ($type !== ProductType::FinishedGoods && filled($fields['selling_price'])) {
            throw new InvalidMasterDataException(InvalidMasterDataException::SELLING_PRICE_NOT_FG);
        }
        $fields['selling_price'] = $type === ProductType::FinishedGoods && filled($fields['selling_price']) ? $fields['selling_price'] : null;
        $fields['unit_of_measure'] = $type->fixedUnit() ?? $fields['unit_of_measure'];

        if ($product && $product->isLocked() && ($product->type !== $type || $product->unit_of_measure !== $fields['unit_of_measure'])) {
            throw new InvalidMasterDataException(InvalidMasterDataException::PRODUCT_LOCKED, $product->product_name);
        }

        if ($product) {
            $product->update($fields);

            return $product;
        }

        return DB::transaction(function () use ($fields, $type) {
            $prefix = Product::codePrefix($type);
            $last = Product::where('product_code', 'like', $prefix.'-%')->lockForUpdate()->pluck('product_code')
                ->map(fn (string $code) => (int) substr($code, strlen($prefix) + 1))
                ->max() ?? 0;

            $product = new Product($fields);
            $product->product_code = sprintf('%s-%03d', $prefix, $last + 1);
            $product->save();

            return $product;
        });
    }
}
