<?php 

namespace App\Actions\Purchasing;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Actions\Inventory\CreateStockMovementAction;
use App\DTO\Inventory\CreateStockMovementData;
use App\DTO\Purchasing\ReceivePurchaseOrderData;
use App\Enums\AccountSystemRole;
use App\Enums\Direction;
use App\Enums\JournalEntryType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidReceiptQuantityException;
use App\Models\InventoryLot;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceipt;
use App\Support\AccountResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceivePurchaseOrderAction
{
    public function handle(ReceivePurchaseOrderData $data): PurchaseOrderReceipt {
        return DB::transaction(function () use ($data) {
            $poItem = PurchaseOrderItem::where('id', $data->purchaseOrderItemId)->lockForUpdate()->firstOrFail();

            if(bccomp($data->quantityReceived, "0", 2) <= 0) throw new InvalidReceiptQuantityException($data->quantityReceived);
            $poItem->purchaseOrder->ensureCanReceive();

            $receipt = PurchaseOrderReceipt::create([
                'purchase_order_item_id' => $data->purchaseOrderItemId,
                'quantity_received' => $data->quantityReceived,
                'purchase_order_receipt_batch_id' => $data->purchaseOrderReceiptBatchId
            ]);

            $landedUnitCost = $poItem->landedUnitCost();

            $lot = InventoryLot::create([
                'lot_number' => (string) Str::uuid(),
                'product_id' => $poItem->product->id,
                'source_type' => 'purchase_order_receipt',
                'source_id' => $receipt->id,
                'quantity_initial' => $data->quantityReceived,
                'quantity_remaining' => $data->quantityReceived,
                'unit_cost' => bcadd($landedUnitCost, '0.005', 2),
                'value_remaining' => self::receiptValue($poItem, $data->quantityReceived),
                'received_at' => now(),
            ]);
            $lot->lot_number = sprintf('LOT-%s-%06d', $lot->received_at->format('Y'), $lot->id);
            $lot->save();

            $poItem->syncQuantityReceived();
            $poItem->save();

            $allFullyReceived = $poItem->purchaseOrder->items()->get()->every(fn($i) => $i->isFullyReceived());

            $target = $allFullyReceived ? PurchaseOrderStatus::FullyReceived : PurchaseOrderStatus::PartiallyReceived;
            if($target !== $poItem->purchaseOrder->status) $poItem->purchaseOrder->transitionTo($target);

            app(CreateStockMovementAction::class)->handle(new CreateStockMovementData(
                productId: $poItem->product->id,
                inventoryLotId: $lot->id,
                direction: Direction::In,
                type: StockMovementType::PurchaseReceived,
                quantity: $data->quantityReceived,
                unitCost: (string) $lot->unit_cost,
                createdBy: $data->receivedBy,
                referenceId: $receipt->id,
                referenceType: 'purchase_order_receipt',
            ));

            app(PostJournalEntryAction::class)->handle(
                lines: array_map(fn (array $line) => [
                    'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], self::journalPlan([['type' => $poItem->product->type, 'amount' => (string) $lot->value_remaining]])),
                type: JournalEntryType::Auto,
                description: __('Purchase receipt for :po — :product', ['po' => $poItem->purchaseOrder->po_number, 'product' => $poItem->product->product_name]),
                userId: $data->receivedBy,
                sourceType: 'purchase_order_receipt',
                sourceId: $receipt->id,
            );

            return $receipt;
        });
    }

    public static function receiptValue(PurchaseOrderItem $poItem, string $quantity): string
    {
        return bcadd(bcmul($quantity, $poItem->landedUnitCost(), 6), '0.005', 2);
    }

    /**
     * @param  array<int, string>  $quantities
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public static function preview(array $quantities): array
    {
        $rows = [];
        foreach (PurchaseOrderItem::with('product')->whereIn('id', array_keys($quantities))->get() as $poItem) {
            $quantity = (string) $quantities[$poItem->id];
            if (is_numeric($quantity) && bccomp($quantity, '0', 2) > 0) {
                $rows[] = ['type' => $poItem->product->type, 'amount' => self::receiptValue($poItem, $quantity)];
            }
        }

        return $rows === [] ? [] : self::journalPlan($rows);
    }

    /**
     * @param  iterable<array{type: \App\Enums\ProductType, amount: string}>  $rows
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public static function journalPlan(iterable $rows): array
    {
        $grouped = \App\Support\InventoryAccounts::group($rows);
        $total = array_reduce($grouped, fn (string $carry, array $row) => bcadd($carry, $row['amount'], 2), '0');

        return [
            ...array_map(fn (array $row) => ['role' => $row['role'], 'account_id' => null, 'debit' => $row['amount'], 'credit' => '0'], array_values($grouped)),
            ['role' => AccountSystemRole::GoodsReceivedNotInvoiced, 'account_id' => null, 'debit' => '0', 'credit' => $total],
        ];
    }
}
