<?php

namespace App\Actions\Warehouse;

use App\Actions\Inventory\CreateStockMovementAction;
use App\DTO\Inventory\CreateStockMovementData;
use App\Enums\Direction;
use App\Enums\DocumentStatus;
use App\Enums\StockMovementType;
use App\Models\InventoryLot;
use App\Models\ProductionReceipt;
use App\Models\ProductionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostProductionReceiptAction
{
    public function handle(ProductionReceipt $receipt, int $userId): ProductionReceipt
    {
        return DB::transaction(function () use ($receipt, $userId) {
            $receipt = ProductionReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $receipt->ensureIsDraft();

            $result = ProductionResult::whereKey($receipt->production_result_id)->lockForUpdate()->firstOrFail();
            SaveProductionReceiptDraftAction::checkReceivable($result, (string) $receipt->quantity, $receipt);

            $received = (string) $receipt->quantity;
            $unitCost = (string) $result->unit_cost;
            $value = bccomp($received, (string) $result->quantity_good, 2) === 0
                ? (string) $result->total_cost
                : bcmul($received, $unitCost, 2);

            $lot = InventoryLot::create([
                'lot_number' => (string) Str::uuid(),
                'product_id' => $receipt->product_id,
                'source_type' => 'production_receipt',
                'source_id' => $receipt->id,
                'quantity_initial' => $received,
                'quantity_remaining' => $received,
                'unit_cost' => $unitCost,
                'value_remaining' => $value,
                'received_at' => now(),
            ]);
            $lot->lot_number = sprintf('LOT-%s-%06d', $lot->received_at->format('Y'), $lot->id);
            $lot->save();

            app(CreateStockMovementAction::class)->handle(new CreateStockMovementData(
                productId: $receipt->product_id,
                inventoryLotId: $lot->id,
                direction: Direction::In,
                type: StockMovementType::ProductionOutput,
                quantity: $received,
                unitCost: $unitCost,
                createdBy: $userId,
                referenceId: $receipt->id,
                referenceType: 'production_receipt',
                totalValue: $value,
            ));

            $receipt->unit_cost = $unitCost;
            $receipt->total_value = $value;
            $receipt->shortage_value = bcsub((string) $result->total_cost, $value, 2);
            $receipt->received_by = $userId;
            $receipt->received_at = now();
            $receipt->transitionTo(DocumentStatus::Posted);

            return $receipt->load('lot');
        });
    }
}
