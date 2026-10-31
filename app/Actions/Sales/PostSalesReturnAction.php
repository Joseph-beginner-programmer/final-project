<?php

namespace App\Actions\Sales;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Actions\Inventory\CreateStockMovementAction;
use App\DTO\Inventory\CreateStockMovementData;
use App\Enums\AccountSystemRole;
use App\Enums\Direction;
use App\Enums\DocumentStatus;
use App\Enums\JournalEntryType;
use App\Enums\ReturnCondition;
use App\Enums\SalesInvoiceStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidSalesReturnException;
use App\Models\Account;
use App\Models\InventoryLot;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesOrderItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Support\AccountResolver;
use App\Support\InventoryAccounts;
use Illuminate\Support\Facades\DB;

class PostSalesReturnAction
{
    public function __construct(
        private PostJournalEntryAction $postJournalEntry,
        private CreateStockMovementAction $createMovement,
    ) {}

    public function handle(SalesReturn $return, int $userId, ?int $refundAccountId = null): SalesReturn
    {
        return DB::transaction(function () use ($return, $userId, $refundAccountId) {
            $return = SalesReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            $return->ensureIsDraft();
            $return->load(['items.product', 'items.deliveryItem.stockMovements', 'salesDelivery.salesOrder', 'customer']);

            SaveSalesReturnDraftAction::checkDate($return->salesDelivery, $return->return_date->toDateString());
            SaveSalesReturnDraftAction::checkReturnable(
                $return->salesDelivery,
                $return->items->mapWithKeys(fn ($item) => [$item->sales_delivery_item_id => ['quantity' => (string) $item->quantity, 'condition' => $item->condition->value]])->all(),
            );

            $invoice = self::invoiceFor($return, lock: true);
            $refills = self::refills($return);
            $lots = InventoryLot::whereIn('id', collect($refills)->flatten(1)->map(fn (array $part) => $part['movement']->inventory_lot_id)->unique())
                ->lockForUpdate()->get()->keyBy('id');

            foreach ($return->items as $item) {
                $cost = '0';
                foreach ($refills[$item->id] ?? [] as $part) {
                    $lot = $lots[$part['movement']->inventory_lot_id];
                    $lot->quantity_remaining = bcadd((string) $lot->quantity_remaining, $part['quantity'], 2);
                    $lot->value_remaining = bcadd((string) $lot->value_remaining, $part['value'], 2);
                    $lot->save();

                    $this->createMovement->handle(new CreateStockMovementData(
                        productId: $item->product_id,
                        inventoryLotId: $lot->id,
                        direction: Direction::In,
                        type: StockMovementType::SalesReturn,
                        quantity: $part['quantity'],
                        unitCost: (string) $part['movement']->unit_cost,
                        createdBy: $userId,
                        referenceId: $item->id,
                        referenceType: 'sales_return_item',
                        totalValue: $part['value'],
                    ));

                    $cost = bcadd($cost, $part['value'], 2);
                }
                $item->cost_total = $cost;
                $item->save();

                $orderItem = SalesOrderItem::whereKey($item->deliveryItem->sales_order_item_id)->lockForUpdate()->firstOrFail();
                $orderItem->quantity_returned = bcadd((string) $orderItem->quantity_returned, (string) $item->quantity, 2);
                $orderItem->save();
            }

            $plan = self::plan($return, $invoice, $refundAccountId);

            if ($invoice) {
                $invoice->returned_amount = bcadd((string) $invoice->returned_amount, $plan['note'], 2);
                $invoice->refunded_amount = bcadd((string) $invoice->refunded_amount, $plan['refund'], 2);
                $invoice->save();
                $invoice->syncPaymentStatus();
            }

            $return->total_amount = $plan['dpp'];
            $return->cost_restocked = $plan['restocked'];
            $return->sales_invoice_id = $invoice?->id;
            $return->credit_note_amount = $plan['note'];
            $return->refund_amount = $plan['refund'];
            $return->refund_account_id = bccomp($plan['refund'], '0', 2) > 0 ? $refundAccountId : null;
            $return->posted_by = $userId;
            $return->posted_at = now();
            $return->transitionTo(DocumentStatus::Posted);

            if (count($plan['lines']) >= 2) {
                $this->postJournalEntry->handle(
                    lines: array_map(fn (array $line) => [
                        'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                    ], $plan['lines']),
                    type: JournalEntryType::Auto,
                    description: __('Sales return :number from :customer', ['number' => $return->return_number, 'customer' => $return->customer->customer_name]),
                    userId: $userId,
                    sourceType: 'sales_return',
                    sourceId: $return->id,
                    entryDate: $return->return_date->toDateString(),
                );
            }

            return $return->load('items');
        });
    }

    public static function invoiceFor(SalesReturn $return, bool $lock = false): ?SalesInvoice
    {
        return SalesInvoice::query()
            ->where('sales_delivery_id', $return->sales_delivery_id)
            ->whereIn('status', [SalesInvoiceStatus::Unpaid->value, SalesInvoiceStatus::PartiallyPaid->value, SalesInvoiceStatus::Paid->value])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * @return array<int, list<array{movement: \App\Models\StockMovement, quantity: string, value: string}>>
     */
    public static function refills(SalesReturn $return): array
    {
        $refills = [];
        foreach ($return->items as $item) {
            if ($item->condition !== ReturnCondition::Good) {
                continue;
            }
            $refills[$item->id] = self::reverseDraws($item->deliveryItem, (string) $item->quantity, $return->id);
        }

        return $refills;
    }

    /**
     * @return list<array{movement: \App\Models\StockMovement, quantity: string, value: string}>
     */
    public static function reverseDraws(SalesDeliveryItem $deliveryItem, string $quantity, int $exceptReturnId): array
    {
        $alreadyBack = bcadd((string) SalesReturnItem::query()
            ->where('sales_delivery_item_id', $deliveryItem->id)
            ->where('condition', ReturnCondition::Good->value)
            ->where('sales_return_id', '!=', $exceptReturnId)
            ->whereHas('salesReturn', fn ($q) => $q->where('status', DocumentStatus::Posted->value))
            ->sum('quantity'), '0', 2);

        $parts = [];
        $stillLeft = $quantity;
        foreach ($deliveryItem->stockMovements->where('direction', Direction::Out)->sortByDesc('id') as $movement) {
            if (bccomp($stillLeft, '0', 2) <= 0) {
                break;
            }
            $drawn = (string) $movement->quantity;
            if (bccomp($alreadyBack, '0', 2) > 0) {
                $skip = bccomp($alreadyBack, $drawn, 2) >= 0 ? $drawn : $alreadyBack;
                $alreadyBack = bcsub($alreadyBack, $skip, 2);
                $drawn = bcsub($drawn, $skip, 2);
                if (bccomp($drawn, '0', 2) <= 0) {
                    continue;
                }
            }
            $take = bccomp($drawn, $stillLeft, 2) <= 0 ? $drawn : $stillLeft;
            $parts[] = [
                'movement' => $movement,
                'quantity' => $take,
                'value' => bccomp($take, (string) $movement->quantity, 2) === 0
                    ? (string) $movement->total_value
                    : bcadd(bcmul($take, (string) $movement->unit_cost, 6), '0.005', 2),
            ];
            $stillLeft = bcsub($stillLeft, $take, 2);
        }

        return $parts;
    }

    /**
     * @return array{lines: list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>, dpp: string, tax: string, note: string, refund: string, refund_needed: bool, restocked: string}
     */
    public static function plan(SalesReturn $return, ?SalesInvoice $invoice, ?int $refundAccountId, bool $validateRefund = true): array
    {
        $return->loadMissing(['items.product', 'items.deliveryItem.stockMovements']);
        $refills = self::refills($return);
        $restockedByItem = collect($refills)->map(fn (array $parts) => array_reduce($parts, fn (string $carry, array $p) => bcadd($carry, $p['value'], 2), '0'));
        $restocked = $restockedByItem->reduce(fn (string $carry, string $v) => bcadd($carry, $v, 2), '0');

        $goods = $return->items->reduce(fn (string $carry, $item) => bcadd($carry, bcadd(bcmul((string) $item->quantity, (string) $item->unit_price, 6), '0.005', 2), 2), '0');
        $percent = (string) ($invoice?->discount_percent ?? $return->salesDelivery?->salesOrder?->discount_percent ?? '0');
        $dpp = bcsub($goods, SaveSalesOrderDraftAction::discountAmount($goods, bcadd($percent, '0', 2)), 2);

        $lines = [];
        $tax = '0';
        $note = '0';
        $refund = '0';
        $refundNeeded = false;

        if ($invoice) {
            $tax = bcadd(bcdiv(bcmul($dpp, (string) $invoice->tax_rate, 6), '100', 6), '0.005', 2);
            $note = bcadd($dpp, $tax, 2);
            $open = $invoice->balance();
            $receivable = bccomp($open, '0', 2) <= 0 ? '0' : (bccomp($note, $open, 2) <= 0 ? $note : $open);
            $refund = bcsub($note, $receivable, 2);
            $refundNeeded = bccomp($refund, '0', 2) > 0;

            if ($refundNeeded && $validateRefund) {
                $account = $refundAccountId ? Account::find($refundAccountId) : null;
                if (! $account) {
                    throw new InvalidSalesReturnException(InvalidSalesReturnException::REFUND_ACCOUNT_REQUIRED);
                }
                if (! $account->is_active || ! $account->is_cash) {
                    throw new InvalidSalesReturnException(InvalidSalesReturnException::NOT_CASH_ACCOUNT);
                }
            }

            $lines[] = ['role' => AccountSystemRole::SalesReturns, 'account_id' => null, 'debit' => $dpp, 'credit' => '0'];
            if (bccomp($tax, '0', 2) > 0) {
                $lines[] = ['role' => AccountSystemRole::VatOut, 'account_id' => null, 'debit' => $tax, 'credit' => '0'];
            }
        }

        $inventory = array_values(InventoryAccounts::group($return->items
            ->filter(fn ($item) => isset($restockedByItem[$item->id]))
            ->map(fn ($item) => ['type' => $item->product->type, 'amount' => $restockedByItem[$item->id]])));
        foreach ($inventory as $row) {
            $lines[] = ['role' => $row['role'], 'account_id' => null, 'debit' => $row['amount'], 'credit' => '0'];
        }

        if ($invoice) {
            $receivable = bcsub($note, $refund, 2);
            if (bccomp($receivable, '0', 2) > 0) {
                $lines[] = ['role' => AccountSystemRole::AccountsReceivable, 'account_id' => null, 'debit' => '0', 'credit' => $receivable];
            }
            if ($refundNeeded) {
                $lines[] = ['role' => null, 'account_id' => $refundAccountId, 'debit' => '0', 'credit' => $refund];
            }
        }
        if (bccomp($restocked, '0', 2) > 0) {
            $lines[] = ['role' => AccountSystemRole::Cogs, 'account_id' => null, 'debit' => '0', 'credit' => $restocked];
        }

        return ['lines' => $lines, 'dpp' => $dpp, 'tax' => $tax, 'note' => $note, 'refund' => $refund, 'refund_needed' => $refundNeeded, 'restocked' => $restocked];
    }
}
