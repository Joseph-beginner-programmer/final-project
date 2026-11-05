<?php

namespace App\Actions\Production;

use App\Actions\Accounting\ReverseJournalEntryAction;
use App\Actions\Inventory\CreateStockMovementAction;
use App\DTO\Inventory\CreateStockMovementData;
use App\Enums\Direction;
use App\Enums\JournalStatus;
use App\Enums\StockMovementType;
use App\Enums\WorkOrderStatus;
use App\Models\InventoryLot;
use App\Models\JournalEntry;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class CancelWorkOrderAction
{
    public function handle(WorkOrder $workOrder, int $userId, string $reason): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $userId, $reason) {
            $workOrder = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();
            $wasInProgress = $workOrder->status === WorkOrderStatus::InProgress;

            $workOrder->cancelled_by = $userId;
            $workOrder->cancelled_at = now();
            $workOrder->cancel_reason = $reason;
            $workOrder->transitionTo(WorkOrderStatus::Cancelled);

            if ($wasInProgress) {
                $materials = $workOrder->materials()->with('stockMovements')->lockForUpdate()->get();

                $lotIds = $materials->flatMap(fn ($material) => $material->stockMovements->pluck('inventory_lot_id'))->unique()->sort()->values();
                $lots = InventoryLot::whereIn('id', $lotIds)->lockForUpdate()->get()->keyBy('id');

                foreach ($materials as $material) {
                    foreach ($material->reverseDraws((string) $material->quantity_planned) as $part) {
                        $lot = $lots[$part['movement']->inventory_lot_id];
                        $lot->quantity_remaining = bcadd((string) $lot->quantity_remaining, $part['quantity'], 2);
                        $lot->value_remaining = bcadd((string) $lot->value_remaining, $part['value'], 2);
                        $lot->save();

                        app(CreateStockMovementAction::class)->handle(new CreateStockMovementData(
                            productId: $material->product_id,
                            inventoryLotId: $lot->id,
                            direction: Direction::In,
                            type: StockMovementType::MaterialReturn,
                            quantity: $part['quantity'],
                            unitCost: (string) $part['movement']->unit_cost,
                            createdBy: $userId,
                            referenceId: $material->id,
                            referenceType: 'work_order_material',
                            totalValue: $part['value'],
                        ));
                    }
                }

                $entry = JournalEntry::where('source_type', 'work_order')
                    ->where('source_id', $workOrder->id)
                    ->where('status', JournalStatus::Posted->value)
                    ->first();

                if ($entry) {
                    app(ReverseJournalEntryAction::class)->handle($entry, $userId);
                }
            }

            return $workOrder;
        });
    }
}
