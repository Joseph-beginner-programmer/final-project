<?php

namespace App\Actions\Purchasing;

use App\Enums\DocumentStatus;
use App\Exceptions\InvalidPurchaseReturnException;
use App\Models\PurchaseOrderReceipt;
use App\Models\PurchaseOrderReceiptBatch;
use App\Models\PurchaseReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SavePurchaseReturnDraftAction
{
    /**
     * @param  array<int, string>  $quantities
     */
    public function handle(PurchaseOrderReceiptBatch $batch, array $quantities, string $returnDate, string $reason, int $userId, ?PurchaseReturn $return = null): PurchaseReturn
    {
        $return?->ensureIsDraft();

        if (blank(trim($reason))) {
            throw new InvalidPurchaseReturnException(InvalidPurchaseReturnException::REASON_REQUIRED);
        }
        self::checkDate($batch, $returnDate);

        return DB::transaction(function () use ($batch, $quantities, $returnDate, $reason, $userId, $return) {
            PurchaseOrderReceiptBatch::whereKey($batch->id)->lockForUpdate()->first();

            $otherDraft = PurchaseReturn::query()
                ->where('purchase_order_receipt_batch_id', $batch->id)
                ->where('status', DocumentStatus::Draft->value)
                ->when($return, fn ($query) => $query->whereKeyNot($return->id))
                ->first();
            if ($otherDraft) {
                throw new InvalidPurchaseReturnException(InvalidPurchaseReturnException::OPEN_DRAFT, $otherDraft->return_number);
            }

            $lines = self::checkReturnable($batch, $quantities);
            $header = ['return_date' => $returnDate, 'reason' => trim($reason)];

            if (! $return) {
                $return = new PurchaseReturn($header + [
                    'purchase_order_id' => $batch->purchase_order_id,
                    'purchase_order_receipt_batch_id' => $batch->id,
                    'supplier_id' => $batch->purchaseOrder->supplier_id,
                    'created_by' => $userId,
                ]);
                $return->status = DocumentStatus::Draft;
                $return->return_number = (string) Str::uuid();
                $return->save();
                $return->return_number = sprintf('RTB-%s-%06d', $return->created_at->format('Y'), $return->id);
                $return->save();
            } else {
                $return->update($header);
            }

            $return->items()->delete();
            $total = '0';
            foreach ($lines as [$receipt, $quantity]) {
                $unitCost = (string) $receipt->inventoryLot->unit_cost;
                $subtotal = bcadd(bcmul($quantity, $unitCost, 6), '0.005', 2);
                $return->items()->create([
                    'purchase_order_receipt_id' => $receipt->id,
                    'product_id' => $receipt->purchaseOrderItem->product_id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'subtotal' => $subtotal,
                ]);
                $total = bcadd($total, $subtotal, 2);
            }
            $return->total_amount = $total;
            $return->save();

            return $return->load('items');
        });
    }

    public static function checkDate(PurchaseOrderReceiptBatch $batch, string $returnDate): void
    {
        $date = Carbon::parse($returnDate)->startOfDay();
        if ($date->gt(now()->startOfDay())) {
            throw new InvalidPurchaseReturnException(InvalidPurchaseReturnException::FUTURE_DATE);
        }
        if ($date->lt($batch->received_at->copy()->startOfDay())) {
            throw new InvalidPurchaseReturnException(InvalidPurchaseReturnException::BEFORE_RECEIPT);
        }
    }

    /**
     * @param  array<int, string>  $quantities
     * @return list<array{0: PurchaseOrderReceipt, 1: string}>
     */
    public static function checkReturnable(PurchaseOrderReceiptBatch $batch, array $quantities): array
    {
        $lines = [];
        foreach ($batch->purchaseOrderReceipts()->with(['purchaseOrderItem.product', 'inventoryLot'])->orderBy('id')->get() as $receipt) {
            $quantity = $quantities[$receipt->id] ?? '0';
            if (! is_numeric($quantity) || bccomp((string) $quantity, '0', 2) <= 0) {
                continue;
            }
            $quantity = bcadd((string) $quantity, '0', 2);
            if (bccomp($quantity, self::returnable($receipt), 2) > 0) {
                throw new InvalidPurchaseReturnException(InvalidPurchaseReturnException::MORE_THAN_RETURNABLE, $receipt->purchaseOrderItem->product->product_name);
            }
            $lines[] = [$receipt, $quantity];
        }

        if ($lines === []) {
            throw new InvalidPurchaseReturnException(InvalidPurchaseReturnException::NO_LINES);
        }

        return $lines;
    }

    public static function returnable(PurchaseOrderReceipt $receipt): string
    {
        $notReturned = bcsub((string) $receipt->quantity_received, $receipt->returned()['quantity'], 2);
        $inStock = (string) ($receipt->inventoryLot?->quantity_remaining ?? '0');

        return bccomp($notReturned, $inStock, 2) <= 0 ? $notReturned : $inStock;
    }
}
