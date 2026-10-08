<?php

namespace App\Actions\Inventory;

use App\DTO\Inventory\CreateStockMovementData;
use App\Enums\Direction;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class CreateStockMovementAction
{
    public function handle(CreateStockMovementData $data): StockMovement
    {
        return DB::transaction(function() use ($data) {
            $product = Product::where('id', '=', $data->productId, 'and')->lockForUpdate()->firstOrFail();
            $newStock = $data->direction === Direction::In ? bcadd($product->current_stock, $data->quantity, 2) : bcsub($product->current_stock, $data->quantity, 2);


            if($data->direction === Direction::Out && bccomp($newStock, '0', 2) < 0) throw new InsufficientStockException($product, $data->quantity, $product->current_stock);

            $product->current_stock = $newStock;
            $product->save();

            $stockMovement = new StockMovement([
                'product_id'=> $data->productId,
                'inventory_lot_id' => $data->inventoryLotId,
                'reference_id' => $data->referenceId,
                'reference_type' => $data->referenceType,
                'direction' => $data->direction,
                'type' => $data->type,
                'quantity' => $data->quantity,
                'unit_cost' => $data->unitCost,
                'total_value' => $data->totalValue ?? bcmul($data->quantity, $data->unitCost, 2),
                'created_by' => $data->createdBy
            ]);
            $stockMovement->save();
            return $stockMovement;
        });
    }
}
