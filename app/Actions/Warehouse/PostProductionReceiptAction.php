<?php

namespace App\Actions\Warehouse;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Actions\Inventory\CreateStockMovementAction;
use App\DTO\Inventory\CreateStockMovementData;
use App\Enums\AccountSystemRole;
use App\Enums\Direction;
use App\Enums\DocumentStatus;
use App\Enums\JournalEntryType;
use App\Enums\StockMovementType;
use App\Models\InventoryLot;
use App\Models\ProductionReceipt;
use App\Models\ProductionResult;
use App\Support\AccountResolver;
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
            $value = self::receivedValue($result, $received);

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

            $lines = array_map(fn (array $line) => [
                    'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], self::journalPlan($receipt->product->type, $value, (string) $result->total_cost));

            app(PostJournalEntryAction::class)->handle(
                lines: $lines,
                type: JournalEntryType::Auto,
                description: __('Production receipt :number', ['number' => $receipt->receipt_number]),
                userId: $userId,
                sourceType: 'production_receipt',
                sourceId: $receipt->id,
            );

            return $receipt->load('lot');
        });
    }

    /**
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public function preview(ProductionReceipt $receipt): array
    {
        $result = $receipt->productionResult;

        return self::journalPlan($receipt->product->type, self::receivedValue($result, (string) $receipt->quantity), (string) $result->total_cost);
    }

    public static function receivedValue(ProductionResult $result, string $received): string
    {
        return bccomp($received, (string) $result->quantity_good, 2) === 0
            ? (string) $result->total_cost
            : bcmul($received, (string) $result->unit_cost, 2);
    }

    /**
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public static function journalPlan(\App\Enums\ProductType $type, string $value, string $resultCost): array
    {
        $lines = [['role' => $type->inventoryRole(), 'account_id' => null, 'debit' => $value, 'credit' => '0']];
        $shortage = bcsub($resultCost, $value, 2);
        if (bccomp($shortage, '0', 2) > 0) {
            $lines[] = ['role' => AccountSystemRole::ProductionLoss, 'account_id' => null, 'debit' => $shortage, 'credit' => '0'];
        }
        $lines[] = ['role' => AccountSystemRole::InventoryWip, 'account_id' => null, 'debit' => '0', 'credit' => $resultCost];

        return $lines;
    }
}
