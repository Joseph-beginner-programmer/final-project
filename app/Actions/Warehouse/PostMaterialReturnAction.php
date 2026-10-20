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
use App\Models\MaterialReturn;
use App\Models\ProductionResult;
use App\Support\AccountResolver;
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

            $postedItems = $return->items()->get();
            $totalReturned = $postedItems->reduce(fn (string $carry, $item) => bcadd($carry, (string) $item->total_cost, 2), '0');
            $netVariance = $postedItems->reduce(fn (string $carry, $item) => bcadd($carry, (string) $item->variance_value, 2), '0');

            $journalLines = [
                ['account_id' => AccountResolver::forRole(AccountSystemRole::InventoryRaw)->id, 'debit' => $totalReturned, 'credit' => '0'],
                ['account_id' => AccountResolver::forRole(AccountSystemRole::InventoryWip)->id, 'debit' => '0', 'credit' => $totalReturned],
            ];
            if (bccomp($netVariance, '0', 2) > 0) {
                $journalLines[] = ['account_id' => AccountResolver::forRole(AccountSystemRole::MaterialVariance)->id, 'debit' => $netVariance, 'credit' => '0'];
                $journalLines[] = ['account_id' => AccountResolver::forRole(AccountSystemRole::InventoryWip)->id, 'debit' => '0', 'credit' => $netVariance];
            } elseif (bccomp($netVariance, '0', 2) < 0) {
                $journalLines[] = ['account_id' => AccountResolver::forRole(AccountSystemRole::InventoryWip)->id, 'debit' => bcmul($netVariance, '-1', 2), 'credit' => '0'];
                $journalLines[] = ['account_id' => AccountResolver::forRole(AccountSystemRole::MaterialVariance)->id, 'debit' => '0', 'credit' => bcmul($netVariance, '-1', 2)];
            }

            app(PostJournalEntryAction::class)->handle(
                lines: $journalLines,
                type: JournalEntryType::Auto,
                description: __('Material return :number', ['number' => $return->return_number]),
                userId: $userId,
                sourceType: 'material_return',
                sourceId: $return->id,
            );

            return $return->load('items');
        });
    }
}
