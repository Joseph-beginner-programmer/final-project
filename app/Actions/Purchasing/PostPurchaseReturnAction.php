<?php

namespace App\Actions\Purchasing;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Actions\Inventory\CreateStockMovementAction;
use App\DTO\Inventory\CreateStockMovementData;
use App\Enums\AccountSystemRole;
use App\Enums\Direction;
use App\Enums\DocumentStatus;
use App\Enums\JournalEntryType;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidPurchaseReturnException;
use App\Models\Account;
use App\Models\InventoryLot;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Support\AccountResolver;
use App\Support\InventoryAccounts;
use Illuminate\Support\Facades\DB;

class PostPurchaseReturnAction
{
    public function __construct(
        private PostJournalEntryAction $postJournalEntry,
        private CreateStockMovementAction $createMovement,
    ) {}

    public function handle(PurchaseReturn $return, int $userId, ?int $refundAccountId = null): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $userId, $refundAccountId) {
            $return = PurchaseReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            $return->ensureIsDraft();
            $return->load(['items.receipt.purchaseOrderItem.product', 'items.receipt.inventoryLot', 'receiptBatch.purchaseOrder']);

            $invoice = self::invoiceFor($return, lock: true);
            if ($invoice && $invoice->status === PurchaseInvoiceStatus::Draft) {
                throw new InvalidPurchaseReturnException(InvalidPurchaseReturnException::DRAFT_INVOICE, $invoice->internal_number);
            }

            SavePurchaseReturnDraftAction::checkDate($return->receiptBatch, $return->return_date->toDateString());
            SavePurchaseReturnDraftAction::checkReturnable(
                $return->receiptBatch,
                $return->items->mapWithKeys(fn ($item) => [$item->purchase_order_receipt_id => (string) $item->quantity])->all(),
            );

            $lots = InventoryLot::whereIn('id', $return->items->map(fn ($item) => $item->receipt->inventoryLot->id))->lockForUpdate()->get()->keyBy('id');
            foreach ($return->items as $item) {
                $item->receipt->setRelation('inventoryLot', $lots[$item->receipt->inventoryLot->id]);
            }
            SavePurchaseReturnDraftAction::checkReturnable(
                $return->receiptBatch,
                $return->items->mapWithKeys(fn ($item) => [$item->purchase_order_receipt_id => (string) $item->quantity])->all(),
            );
            $plan = self::plan($return, $invoice, $refundAccountId);

            foreach ($return->items as $item) {
                $lot = $lots[$item->receipt->inventoryLot->id];
                $value = self::lotValue($lot, (string) $item->quantity);
                $lot->quantity_remaining = bcsub((string) $lot->quantity_remaining, (string) $item->quantity, 2);
                $lot->value_remaining = bcsub((string) $lot->value_remaining, $value, 2);
                $lot->save();

                $this->createMovement->handle(new CreateStockMovementData(
                    productId: $item->product_id,
                    inventoryLotId: $lot->id,
                    direction: Direction::Out,
                    type: StockMovementType::PurchaseReturn,
                    quantity: (string) $item->quantity,
                    unitCost: (string) $lot->unit_cost,
                    createdBy: $userId,
                    referenceId: $item->id,
                    referenceType: 'purchase_return_item',
                    totalValue: $value,
                ));

                $item->unit_cost = (string) $lot->unit_cost;
                $item->subtotal = $value;
                $item->invoice_unit_price = $plan['prices'][$item->id] ?? null;
                $item->invoice_amount = $plan['amounts'][$item->id] ?? null;
                $item->save();

                $poItem = PurchaseOrderItem::whereKey($item->receipt->purchase_order_item_id)->lockForUpdate()->firstOrFail();
                $poItem->quantity_returned = bcadd((string) $poItem->quantity_returned, (string) $item->quantity, 2);
                $poItem->save();
            }

            $po = PurchaseOrder::whereKey($return->purchase_order_id)->lockForUpdate()->firstOrFail();
            if (in_array($po->status, [PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::FullyReceived], true)) {
                $target = $po->items()->get()->every(fn (PurchaseOrderItem $i) => $i->isFullyReceived())
                    ? PurchaseOrderStatus::FullyReceived
                    : PurchaseOrderStatus::PartiallyReceived;
                if ($target !== $po->status) {
                    $po->transitionTo($target);
                }
            }

            if ($invoice) {
                $invoice->returned_amount = bcadd((string) $invoice->returned_amount, $plan['note'], 2);
                $invoice->refunded_amount = bcadd((string) $invoice->refunded_amount, $plan['refund'], 2);
                $invoice->save();
                $invoice->syncPaymentStatus();
            }

            $return->total_amount = $plan['value'];
            $return->purchase_invoice_id = $invoice?->id;
            $return->debit_note_amount = $plan['note'];
            $return->refund_amount = $plan['refund'];
            $return->refund_account_id = bccomp($plan['refund'], '0', 2) > 0 ? $refundAccountId : null;
            $return->posted_by = $userId;
            $return->posted_at = now();
            $return->transitionTo(DocumentStatus::Posted);

            $this->postJournalEntry->handle(
                lines: array_map(fn (array $line) => [
                    'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], $plan['lines']),
                type: JournalEntryType::Auto,
                description: __('Purchase return :number to :supplier', ['number' => $return->return_number, 'supplier' => $return->supplier->supplier_name]),
                userId: $userId,
                sourceType: 'purchase_return',
                sourceId: $return->id,
                entryDate: $return->return_date->toDateString(),
            );

            return $return->load('items');
        });
    }

    public static function invoiceFor(PurchaseReturn $return, bool $lock = false): ?PurchaseInvoice
    {
        return PurchaseInvoice::query()
            ->where('purchase_order_receipt_batch_id', $return->purchase_order_receipt_batch_id)
            ->where('status', '!=', PurchaseInvoiceStatus::Cancelled->value)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->with('items')
            ->first();
    }

    public static function lotValue(InventoryLot $lot, string $quantity): string
    {
        return bccomp($quantity, (string) $lot->quantity_remaining, 2) === 0
            ? (string) $lot->value_remaining
            : bcadd(bcmul($quantity, (string) $lot->unit_cost, 6), '0.005', 2);
    }

    /**
     * @return array{lines: list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>, value: string, note: string, tax: string, refund: string, refund_needed: bool, prices: array<int, string>, amounts: array<int, string>}
     */
    public static function plan(PurchaseReturn $return, ?PurchaseInvoice $invoice, ?int $refundAccountId, bool $validateRefund = true): array
    {
        $return->loadMissing(['items.receipt.inventoryLot', 'items.product']);
        $values = $return->items->mapWithKeys(fn ($item) => [$item->id => self::lotValue($item->receipt->inventoryLot, (string) $item->quantity)]);
        $value = $values->reduce(fn (string $carry, string $v) => bcadd($carry, $v, 2), '0');
        $inventory = array_values(InventoryAccounts::group($return->items->map(fn ($item) => ['type' => $item->product->type, 'amount' => $values[$item->id]])));

        if (! $invoice || $invoice->status === PurchaseInvoiceStatus::Draft) {
            return [
                'lines' => [
                    ['role' => AccountSystemRole::GoodsReceivedNotInvoiced, 'account_id' => null, 'debit' => $value, 'credit' => '0'],
                    ...array_map(fn (array $row) => ['role' => $row['role'], 'account_id' => null, 'debit' => '0', 'credit' => $row['amount']], $inventory),
                ],
                'value' => $value, 'note' => '0', 'tax' => '0', 'refund' => '0', 'refund_needed' => false, 'prices' => [], 'amounts' => [],
            ];
        }

        $prices = [];
        $amounts = [];
        foreach ($return->items as $item) {
            $price = (string) ($invoice->items->firstWhere('purchase_order_receipt_id', $item->purchase_order_receipt_id)?->unit_price ?? $item->unit_cost);
            $prices[$item->id] = $price;
            $amounts[$item->id] = bcadd(bcmul((string) $item->quantity, $price, 6), '0.005', 2);
        }
        $goods = array_reduce($amounts, fn (string $carry, string $a) => bcadd($carry, $a, 2), '0');
        $tax = bcadd(bcdiv(bcmul($goods, (string) $invoice->tax_rate, 6), '100', 6), '0.005', 2);
        $note = bcadd($goods, $tax, 2);
        $open = $invoice->balance();
        $payable = bccomp($open, '0', 2) <= 0 ? '0' : (bccomp($note, $open, 2) <= 0 ? $note : $open);
        $refund = bcsub($note, $payable, 2);
        $refundNeeded = bccomp($refund, '0', 2) > 0;

        if ($refundNeeded && $validateRefund) {
            $account = $refundAccountId ? Account::find($refundAccountId) : null;
            if (! $account) {
                throw new InvalidPurchaseReturnException(InvalidPurchaseReturnException::REFUND_ACCOUNT_REQUIRED);
            }
            if (! $account->is_active || ! $account->is_cash) {
                throw new InvalidPurchaseReturnException(InvalidPurchaseReturnException::NOT_CASH_ACCOUNT);
            }
        }

        $variance = bcsub($goods, $value, 2);
        $lines = [];
        if (bccomp($payable, '0', 2) > 0) {
            $lines[] = ['role' => AccountSystemRole::AccountsPayable, 'account_id' => null, 'debit' => $payable, 'credit' => '0'];
        }
        if ($refundNeeded) {
            $lines[] = ['role' => null, 'account_id' => $refundAccountId, 'debit' => $refund, 'credit' => '0'];
        }
        if (bccomp($variance, '0', 2) < 0) {
            $lines[] = ['role' => AccountSystemRole::PurchasePriceVariance, 'account_id' => null, 'debit' => bcmul($variance, '-1', 2), 'credit' => '0'];
        }
        foreach ($inventory as $row) {
            $lines[] = ['role' => $row['role'], 'account_id' => null, 'debit' => '0', 'credit' => $row['amount']];
        }
        if (bccomp($tax, '0', 2) > 0) {
            $lines[] = ['role' => AccountSystemRole::VatIn, 'account_id' => null, 'debit' => '0', 'credit' => $tax];
        }
        if (bccomp($variance, '0', 2) > 0) {
            $lines[] = ['role' => AccountSystemRole::PurchasePriceVariance, 'account_id' => null, 'debit' => '0', 'credit' => $variance];
        }

        return ['lines' => $lines, 'value' => $value, 'note' => $note, 'tax' => $tax, 'refund' => $refund, 'refund_needed' => $refundNeeded, 'prices' => $prices, 'amounts' => $amounts];
    }
}
