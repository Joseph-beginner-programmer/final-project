<?php

namespace App\Actions\MasterData;

use App\Models\Product;

/**
 * Writes a calculated safety stock / reorder point suggestion onto the product.
 * Deliberately a plain editable field afterwards, not locked - the calculation is a
 * starting point the Manager can still override by hand on the Products page.
 */
class ApplySafetyStockSuggestionAction
{
    public function handle(Product $product, string $safetyStock, string $reorderPoint): Product
    {
        $product->update([
            'safety_stock' => $safetyStock,
            'reorder_point' => $reorderPoint,
        ]);

        return $product;
    }
}
