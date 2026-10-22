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
use App\Support\InventoryAccounts;
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
                lines: array_map(fn (array $line) => [
                    'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], self::journalPlan($items->map(fn ($item) => ['type' => $item->product->type, 'amount' => (string) $item->total_cost]))),
                type: JournalEntryType::Auto,
                description: __('Material issue :number', ['number' => $issue->issue_number]),
                userId: $userId,
                sourceType: 'material_issue',
                sourceId: $issue->id,
            );

            return $issue->load('items.product');
        });
    }

    /**
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public function preview(MaterialIssue $issue): array
    {
        $lotsByProduct = [];
        $rows = $issue->items()->with('product')->get()->map(function ($item) use (&$lotsByProduct) {
            $lotsByProduct[$item->product_id] ??= $this->fifo->availableLots($item->product_id);

            return ['type' => $item->product->type, 'amount' => $this->fifo->estimate($lotsByProduct[$item->product_id], (string) $item->quantity)];
        });

        return self::journalPlan($rows);
    }

    /**
     * @param  iterable<array{type: \App\Enums\ProductType, amount: string}>  $rows
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public static function journalPlan(iterable $rows): array
    {
        $grouped = InventoryAccounts::group($rows);
        $total = array_reduce($grouped, fn (string $carry, array $row) => bcadd($carry, $row['amount'], 2), '0');

        return [
            ['role' => AccountSystemRole::InventoryWip, 'account_id' => null, 'debit' => $total, 'credit' => '0'],
            ...array_map(fn (array $row) => ['role' => $row['role'], 'account_id' => null, 'debit' => '0', 'credit' => $row['amount']], array_values($grouped)),
        ];
    }
}
