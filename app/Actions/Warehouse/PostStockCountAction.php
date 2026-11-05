<?php

namespace App\Actions\Warehouse;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Actions\Inventory\CreateStockMovementAction;
use App\Actions\Inventory\DrawStockFifoAction;
use App\DTO\Inventory\CreateStockMovementData;
use App\Enums\AccountSystemRole;
use App\Enums\Direction;
use App\Enums\DocumentStatus;
use App\Enums\JournalEntryType;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidStockCountException;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Support\AccountResolver;
use App\Support\InventoryAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PostStockCountAction
{
    public function handle(StockCount $count, int $userId): StockCount
    {
        return DB::transaction(function () use ($count, $userId) {
            $count = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            $count->ensureIsDraft();
            $count->load('items');

            if ($count->items->contains(fn ($item) => $item->counted_quantity === null)) {
                throw new InvalidStockCountException(InvalidStockCountException::NOT_FULLY_COUNTED);
            }

            foreach ($count->items as $item) {
                $this->applyItem($item, $userId);
            }

            $count->posted_by = $userId;
            $count->posted_at = now();
            $count->transitionTo(DocumentStatus::Posted);

            $plan = self::journalPlan($count->items()->with('product')->get()->map(fn ($item) => [
                'type' => $item->product->type,
                'amount' => (string) $item->adjustment_value,
            ]));

            if ($plan !== []) {
                $lines = array_map(fn (array $line) => [
                    'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], $plan);

                app(PostJournalEntryAction::class)->handle(
                    lines: $lines,
                    type: JournalEntryType::Auto,
                    description: __('Stock count :number', ['number' => $count->count_number]),
                    userId: $userId,
                    sourceType: 'stock_count',
                    sourceId: $count->id,
                );
            }

            return $count->load('items.product');
        });
    }

    private function applyItem(StockCountItem $item, int $userId): void
    {
        $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
        $systemQty = (string) $product->current_stock;
        $variance = bcsub((string) $item->counted_quantity, $systemQty, 2);

        $item->system_quantity = $systemQty;
        $item->variance_quantity = $variance;

        if (bccomp($variance, '0', 2) === 0) {
            $item->unit_cost = '0';
            $item->adjustment_value = '0';
            $item->save();

            return;
        }

        if (bccomp($variance, '0', 2) < 0) {
            $shortage = bcmul($variance, '-1', 2);
            $lots = app(DrawStockFifoAction::class)->lockAvailableLots($product->id);
            $value = app(DrawStockFifoAction::class)->draw($lots, $product->id, $shortage, StockMovementType::StockAdjustment, $userId, 'stock_count_item', $item->id);

            $item->unit_cost = bccomp($shortage, '0', 2) > 0 ? bcdiv($value, $shortage, 2) : '0';
            $item->adjustment_value = bcmul($value, '-1', 2);
            $item->save();

            return;
        }

        $unitCost = $product->averageUnitCost();
        $value = bcmul($variance, $unitCost, 2);

        $lot = InventoryLot::create([
            'lot_number' => (string) Str::uuid(),
            'product_id' => $product->id,
            'source_type' => 'stock_count_item',
            'source_id' => $item->id,
            'quantity_initial' => $variance,
            'quantity_remaining' => $variance,
            'unit_cost' => $unitCost,
            'value_remaining' => $value,
            'received_at' => now(),
        ]);
        $lot->lot_number = sprintf('LOT-%s-%06d', $lot->received_at->format('Y'), $lot->id);
        $lot->save();

        app(CreateStockMovementAction::class)->handle(new CreateStockMovementData(
            productId: $product->id,
            inventoryLotId: $lot->id,
            direction: Direction::In,
            type: StockMovementType::StockAdjustment,
            quantity: $variance,
            unitCost: $unitCost,
            createdBy: $userId,
            referenceId: $item->id,
            referenceType: 'stock_count_item',
            totalValue: $value,
        ));

        $item->unit_cost = $unitCost;
        $item->adjustment_value = $value;
        $item->save();
    }

    /**
     * @param  iterable<array{type: \App\Enums\ProductType, amount: string}>  $rows
     * @return list<array{role: AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public static function journalPlan(iterable $rows): array
    {
        $grouped = InventoryAccounts::group($rows);
        $lines = [];
        $net = '0';

        foreach ($grouped as $row) {
            $net = bcadd($net, $row['amount'], 2);
            $lines[] = bccomp($row['amount'], '0', 2) > 0
                ? ['role' => $row['role'], 'account_id' => null, 'debit' => $row['amount'], 'credit' => '0']
                : ['role' => $row['role'], 'account_id' => null, 'debit' => '0', 'credit' => bcmul($row['amount'], '-1', 2)];
        }

        if (bccomp($net, '0', 2) > 0) {
            $lines[] = ['role' => AccountSystemRole::InventoryAdjustment, 'account_id' => null, 'debit' => '0', 'credit' => $net];
        } elseif (bccomp($net, '0', 2) < 0) {
            $lines[] = ['role' => AccountSystemRole::InventoryAdjustment, 'account_id' => null, 'debit' => bcmul($net, '-1', 2), 'credit' => '0'];
        }

        return $lines;
    }

    /**
     * @return list<array{role: AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public function preview(StockCount $count): array
    {
        $count->loadMissing('items.product');

        $rows = [];
        foreach ($count->items as $item) {
            if ($item->counted_quantity === null) {
                continue;
            }

            $variance = bcsub((string) $item->counted_quantity, (string) $item->product->current_stock, 2);
            if (bccomp($variance, '0', 2) === 0) {
                continue;
            }

            if (bccomp($variance, '0', 2) < 0) {
                $shortage = bcmul($variance, '-1', 2);
                $lots = app(DrawStockFifoAction::class)->availableLots($item->product_id);
                $value = app(DrawStockFifoAction::class)->estimate($lots, $shortage);
                $rows[] = ['type' => $item->product->type, 'amount' => bcmul($value, '-1', 2)];
            } else {
                $value = bcmul($variance, $item->product->averageUnitCost(), 2);
                $rows[] = ['type' => $item->product->type, 'amount' => $value];
            }
        }

        return self::journalPlan($rows);
    }
}
