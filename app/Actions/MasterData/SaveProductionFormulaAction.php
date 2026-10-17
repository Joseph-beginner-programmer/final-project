<?php

namespace App\Actions\MasterData;

use App\Enums\ProductType;
use App\Exceptions\InvalidMasterDataException;
use App\Models\Product;
use App\Models\ProductionFormula;
use Illuminate\Support\Facades\DB;

class SaveProductionFormulaAction
{
    /**
     * @param  array<int, string>  $materials  material product id => quantity per output_quantity
     */
    public function handle(int $productId, int $workCenterId, string $outputQuantity, bool $isActive, array $materials, int $userId, ?ProductionFormula $formula = null): ProductionFormula
    {
        $product = Product::findOrFail($formula?->product_id ?? $productId);

        if (! in_array($product->type, [ProductType::Wip, ProductType::FinishedGoods], true)) {
            throw new InvalidMasterDataException(InvalidMasterDataException::FORMULA_PRODUCT_TYPE);
        }

        if (! $formula && ProductionFormula::where('product_id', $product->id)->exists()) {
            throw new InvalidMasterDataException(InvalidMasterDataException::FORMULA_EXISTS, $product->product_name);
        }

        $materials = array_filter($materials, fn (string $quantity) => bccomp($quantity ?: '0', '0', 4) > 0);
        if ($materials === []) {
            throw new InvalidMasterDataException(InvalidMasterDataException::FORMULA_NO_MATERIALS);
        }

        foreach (array_keys($materials) as $materialId) {
            if ($materialId === $product->id) {
                throw new InvalidMasterDataException(InvalidMasterDataException::FORMULA_SELF);
            }
            $material = Product::findOrFail($materialId);
            if (! in_array($material->type, [ProductType::RawMaterial, ProductType::Wip], true)) {
                throw new InvalidMasterDataException(InvalidMasterDataException::FORMULA_MATERIAL_TYPE, $material->product_name);
            }
        }

        return DB::transaction(function () use ($product, $workCenterId, $outputQuantity, $isActive, $materials, $userId, $formula) {
            $fields = [
                'work_center_id' => $workCenterId,
                'output_quantity' => $outputQuantity,
                'is_active' => $isActive,
            ];

            if ($formula) {
                $formula->update($fields);
            } else {
                $formula = new ProductionFormula($fields + ['product_id' => $product->id, 'created_by' => $userId]);
                $formula->formula_code = 'F-'.$product->product_code;
                $formula->save();
            }

            $formula->items()->delete();
            foreach ($materials as $materialId => $quantity) {
                $formula->items()->create(['material_product_id' => $materialId, 'quantity' => $quantity]);
            }

            return $formula->load('items');
        });
    }
}
