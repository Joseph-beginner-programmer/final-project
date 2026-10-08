<?php

namespace App\Actions\Production;

use App\Actions\Inventory\CreateStockMovementAction;
use App\DTO\Inventory\CreateStockMovementData;
use App\Enums\Direction;
use App\Enums\DocumentStatus;
use App\Enums\StockMovementType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\WorkOrderNotIssuableException;
use App\Models\InventoryLot;
use App\Models\MaterialIssue;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posting = the goods physically leave the warehouse. For every line, draw from the oldest lots
 * first (FIFO), writing one OUT stock movement per lot drawn from, and record the line's cost.
 * All or nothing: if any line is short, nothing moves.
 */
class PostMaterialIssueAction
{
    public function handle(MaterialIssue $issue, int $userId): MaterialIssue
    {
        return DB::transaction(function () use ($issue, $userId) {
            // re-read under locks: another user may have posted/cancelled this issue or changed the WO meanwhile
            $issue = MaterialIssue::whereKey($issue->id)->lockForUpdate()->firstOrFail();
            $issue->ensureIsDraft();

            $workOrder = WorkOrder::whereKey($issue->work_order_id)->lockForUpdate()->firstOrFail();
            if (! $workOrder->canReceiveMaterials()) {
                throw new WorkOrderNotIssuableException($workOrder);
            }

            $items = $issue->items()->with('product')->get();

            // 1. lock every lot we might draw from and check all lines BEFORE touching anything
            $lotsByProduct = [];
            foreach ($items as $item) {
                $lots = $this->availableLots($item->product_id);
                $available = $lots->reduce(fn (string $carry, InventoryLot $lot) => bcadd($carry, (string) $lot->quantity_remaining, 2), '0');

                if (bccomp($available, (string) $item->quantity, 2) < 0) {
                    throw new InsufficientStockException($item->product, (string) $item->quantity, $available);
                }

                $lotsByProduct[$item->product_id] = $lots;
            }

            // 2. draw: oldest lot first, one OUT movement per lot used
            foreach ($items as $item) {
                $stillNeeded = (string) $item->quantity;
                $lineCost = '0';

                foreach ($lotsByProduct[$item->product_id] as $lot) {
                    if (bccomp($stillNeeded, '0', 2) <= 0) {
                        break;
                    }

                    $remaining = (string) $lot->quantity_remaining;
                    $take = bccomp($remaining, $stillNeeded, 2) <= 0 ? $remaining : $stillNeeded;
                    $emptiesLot = bccomp($take, $remaining, 2) === 0;

                    // the draw that empties a lot takes its whole remaining value, so rounding never strands rupiah
                    $value = $emptiesLot ? (string) $lot->value_remaining : bcmul($take, (string) $lot->unit_cost, 2);

                    $lot->quantity_remaining = bcsub($remaining, $take, 2);
                    $lot->value_remaining = bcsub((string) $lot->value_remaining, $value, 2);
                    $lot->save();

                    app(CreateStockMovementAction::class)->handle(new CreateStockMovementData(
                        productId: $item->product_id,
                        inventoryLotId: $lot->id,
                        direction: Direction::Out,
                        type: StockMovementType::ProductionInput,
                        quantity: $take,
                        unitCost: (string) $lot->unit_cost,
                        createdBy: $userId,
                        referenceId: $item->id,
                        referenceType: 'material_issue_item',
                        totalValue: $value,
                    ));

                    $lineCost = bcadd($lineCost, $value, 2);
                    $stillNeeded = bcsub($stillNeeded, $take, 2);
                }

                $item->total_cost = $lineCost;
                $item->save();
            }

            // 3. the document is now issued
            $issue->issued_by = $userId;
            $issue->issued_at = now();
            $issue->transitionTo(DocumentStatus::Posted);

            // first posted issue: materials left the warehouse → production has started (decided 2026-10-07)
            if ($workOrder->status === WorkOrderStatus::Released) {
                $workOrder->transitionTo(WorkOrderStatus::InProgress);
            }

            return $issue->load('items.product');
        });
    }

    /**
     * FIFO order: oldest received first, then lowest id — locked so a concurrent post can't draw the same stock.
     *
     * @return Collection<int, InventoryLot>
     */
    private function availableLots(int $productId): Collection
    {
        return InventoryLot::query()
            ->where('product_id', $productId)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
