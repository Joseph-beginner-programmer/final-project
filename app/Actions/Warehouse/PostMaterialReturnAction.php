<?php

namespace App\Actions\Warehouse;

use App\Actions\Inventory\CreateStockMovementAction;
use App\DTO\Inventory\CreateStockMovementData;
use App\Enums\Direction;
use App\Enums\DocumentStatus;
use App\Enums\StockMovementType;
use App\Models\InventoryLot;
use App\Models\MaterialReturn;
use App\Models\ProductionResult;
use Illuminate\Support\Facades\DB;

class PostMaterialReturnAction
{
    public function handle(MaterialReturn $return, int $userId): MaterialReturn
    {
        return DB::transaction(function () use ($return, $userId) {
            $return = MaterialReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            $return->ensureIsDraft();
            $return->load('items');

            $result = ProductionResult::with('workOrder')->findOrFail($return->production_result_id);

            $returnedByProduct = $return->items->groupBy('product_id')
                ->map(fn ($rows) => $rows->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->quantity, 2), '0'))
                ->all();

            $lines = SaveMaterialReturnDraftAction::checkReturnable($result, $returnedByProduct, $return, lock: true);

            $lotIds = $lines->flatMap(fn ($line) => $line->stockMovements->pluck('inventory_lot_id'))->unique()->sort()->values();
            $lots = InventoryLot::whereIn('id', $lotIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $return->items()->delete();

            foreach (SaveMaterialReturnDraftAction::allocate($lines, $returnedByProduct) as $row) {
                $line = $row['line'];
                $item = $return->items()->create([
                    'material_issue_item_id' => $line->id,
                    'product_id' => $line->product_id,
                    'quantity_expected' => $row['expected'],
                    'quantity' => $row['quantity'],
                ]);

                $returnedValue = '0';
                foreach ($line->reverseDraws($row['quantity']) as $part) {
                    $lot = $lots[$part['movement']->inventory_lot_id];
                    $lot->quantity_remaining = bcadd((string) $lot->quantity_remaining, $part['quantity'], 2);
                    $lot->value_remaining = bcadd((string) $lot->value_remaining, $part['value'], 2);
                    $lot->save();

                    app(CreateStockMovementAction::class)->handle(new CreateStockMovementData(
                        productId: $line->product_id,
                        inventoryLotId: $lot->id,
                        direction: Direction::In,
                        type: StockMovementType::MaterialReturn,
                        quantity: $part['quantity'],
                        unitCost: (string) $part['movement']->unit_cost,
                        createdBy: $userId,
                        referenceId: $item->id,
                        referenceType: 'material_return_item',
                        totalValue: $part['value'],
                    ));

                    $returnedValue = bcadd($returnedValue, $part['value'], 2);
                }

                $item->total_cost = $returnedValue;
                $item->variance_value = bcsub($line->leftoverValue($row['expected']), $returnedValue, 2);
                $item->save();
            }

            $return->received_by = $userId;
            $return->received_at = now();
            $return->transitionTo(DocumentStatus::Posted);

            return $return->load('items');
        });
    }
}
