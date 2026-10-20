<?php

namespace App\Actions\Production;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Actions\Inventory\DrawStockFifoAction;
use App\Enums\AccountSystemRole;
use App\Enums\DocumentStatus;
use App\Enums\JournalEntryType;
use App\Enums\StockMovementType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\WorkOrderNotIssuableException;
use App\Models\MaterialIssue;
use App\Models\WorkOrder;
use App\Support\AccountResolver;
use Illuminate\Support\Facades\DB;

class PostMaterialIssueAction
{
    public function __construct(
        private readonly DrawStockFifoAction $fifo,
    ) {}

    public function handle(MaterialIssue $issue, int $userId): MaterialIssue
    {
        return DB::transaction(function () use ($issue, $userId) {
            $issue = MaterialIssue::whereKey($issue->id)->lockForUpdate()->firstOrFail();
            $issue->ensureIsDraft();

            $workOrder = WorkOrder::whereKey($issue->work_order_id)->lockForUpdate()->firstOrFail();
            if (! $workOrder->canReceiveMaterials()) {
                throw new WorkOrderNotIssuableException($workOrder);
            }

            $items = $issue->items()->with('product')->get();

            $lotsByProduct = [];
            foreach ($items as $item) {
                $lots = $this->fifo->lockAvailableLots($item->product_id);
                $available = $this->fifo->available($lots);

                if (bccomp($available, (string) $item->quantity, 2) < 0) {
                    throw new InsufficientStockException($item->product, (string) $item->quantity, $available);
                }

                $lotsByProduct[$item->product_id] = $lots;
            }

            foreach ($items as $item) {
                $item->total_cost = $this->fifo->draw(
                    $lotsByProduct[$item->product_id],
                    $item->product_id,
                    (string) $item->quantity,
                    StockMovementType::ProductionInput,
                    $userId,
                    'material_issue_item',
                    $item->id,
                );
                $item->save();
            }

            $issue->issued_by = $userId;
            $issue->issued_at = now();
            $issue->transitionTo(DocumentStatus::Posted);

            if ($workOrder->status === WorkOrderStatus::Released) {
                $workOrder->transitionTo(WorkOrderStatus::InProgress);
            }

            $totalCost = $items->reduce(fn (string $carry, $item) => bcadd($carry, (string) $item->total_cost, 2), '0');

            app(PostJournalEntryAction::class)->handle(
                lines: [
                    ['account_id' => AccountResolver::forRole(AccountSystemRole::InventoryWip)->id, 'debit' => $totalCost, 'credit' => '0'],
                    ['account_id' => AccountResolver::forRole(AccountSystemRole::InventoryRaw)->id, 'debit' => '0', 'credit' => $totalCost],
                ],
                type: JournalEntryType::Auto,
                description: __('Material issue :number', ['number' => $issue->issue_number]),
                userId: $userId,
                sourceType: 'material_issue',
                sourceId: $issue->id,
            );

            return $issue->load('items.product');
        });
    }
}
