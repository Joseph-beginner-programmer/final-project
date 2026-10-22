<?php

namespace App\Actions\Warehouse;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Actions\Inventory\DrawStockFifoAction;
use App\Enums\AccountSystemRole;
use App\Enums\DocumentStatus;
use App\Enums\JournalEntryType;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\SalesDelivery;
use App\Models\SalesOrder;
use App\Support\AccountResolver;
use App\Support\InventoryAccounts;
use Illuminate\Support\Facades\DB;

class PostSalesDeliveryAction
{
    public function __construct(
        private readonly DrawStockFifoAction $fifo,
    ) {}

    public function handle(SalesDelivery $delivery, int $userId): SalesDelivery
    {
        return DB::transaction(function () use ($delivery, $userId) {
            $delivery = SalesDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $delivery->ensureIsDraft();

            $order = SalesOrder::whereKey($delivery->sales_order_id)->lockForUpdate()->firstOrFail();
            $quantities = $delivery->items()->pluck('quantity', 'sales_order_item_id')->map(fn ($q) => (string) $q)->all();
            SaveSalesDeliveryDraftAction::checkDeliverable($order, $quantities, $delivery);

            $items = $delivery->items()->with(['product', 'salesOrderItem'])->get();

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
                $item->cogs_total = $this->fifo->draw(
                    $lotsByProduct[$item->product_id],
                    $item->product_id,
                    (string) $item->quantity,
                    StockMovementType::SalesShipment,
                    $userId,
                    'sales_delivery_item',
                    $item->id,
                );
                $item->save();

                $orderItem = $item->salesOrderItem;
                $orderItem->quantity_delivered = bcadd((string) $orderItem->quantity_delivered, (string) $item->quantity, 2);
                $orderItem->save();
            }

            $delivery->delivered_by = $userId;
            $delivery->delivered_at = now();
            $delivery->transitionTo(DocumentStatus::Posted);

            $order->syncDeliveryStatus();

            $totalCogs = $items->reduce(fn (string $carry, $item) => bcadd($carry, (string) $item->cogs_total, 2), '0');

            app(PostJournalEntryAction::class)->handle(
                lines: array_map(fn (array $line) => [
                    'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], self::journalPlan($items->map(fn ($item) => ['type' => $item->product->type, 'amount' => (string) $item->cogs_total]))),
                type: JournalEntryType::Auto,
                description: __('Sales delivery :number', ['number' => $delivery->delivery_number]),
                userId: $userId,
                sourceType: 'sales_delivery',
                sourceId: $delivery->id,
            );

            return $delivery->load('items.product');
        });
    }

    /**
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public function preview(SalesDelivery $delivery): array
    {
        $lotsByProduct = [];
        $rows = $delivery->items()->with('product')->get()->map(function ($item) use (&$lotsByProduct) {
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
            ['role' => AccountSystemRole::Cogs, 'account_id' => null, 'debit' => $total, 'credit' => '0'],
            ...array_map(fn (array $row) => ['role' => $row['role'], 'account_id' => null, 'debit' => '0', 'credit' => $row['amount']], array_values($grouped)),
        ];
    }
}
