<?php

namespace App\Actions\Warehouse;

use App\Enums\DocumentStatus;
use App\Exceptions\InvalidProductionReceiptException;
use App\Models\ProductionReceipt;
use App\Models\ProductionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveProductionReceiptDraftAction
{
    public function handle(ProductionResult $result, string $quantity, ?string $notes, int $userId, ?ProductionReceipt $receipt = null): ProductionReceipt
    {
        $receipt?->ensureIsDraft();

        self::checkReceivable($result, $quantity, $receipt);

        return DB::transaction(function () use ($result, $quantity, $notes, $userId, $receipt) {
            $fields = [
                'quantity' => $quantity,
                'notes' => filled($notes) ? $notes : null,
            ];

            if (! $receipt) {
                $receipt = new ProductionReceipt($fields + [
                    'production_result_id' => $result->id,
                    'product_id' => $result->workOrder->product_id,
                    'quantity_expected' => $result->quantity_good,
                    'created_by' => $userId,
                ]);
                $receipt->status = DocumentStatus::Draft;
                $receipt->receipt_number = (string) Str::uuid();
                $receipt->save();

                $receipt->receipt_number = sprintf('PRC-%s-%06d', $receipt->created_at->format('Y'), $receipt->id);
                $receipt->save();
            } else {
                $receipt->update($fields);
            }

            return $receipt;
        });
    }

    public static function checkReceivable(ProductionResult $result, string $quantity, ?ProductionReceipt $current = null): void
    {
        if ($result->status !== DocumentStatus::Posted) {
            throw new InvalidProductionReceiptException(InvalidProductionReceiptException::RESULT_NOT_POSTED);
        }

        $other = $result->productionReceipts()
            ->where('status', '!=', DocumentStatus::Cancelled->value)
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->first();
        if ($other) {
            throw new InvalidProductionReceiptException(InvalidProductionReceiptException::ALREADY_RECEIVED, $other->receipt_number);
        }

        if (bccomp($quantity ?: '0', '0', 2) <= 0) {
            throw new InvalidProductionReceiptException(InvalidProductionReceiptException::QUANTITY_REQUIRED);
        }

        if (bccomp($quantity, (string) $result->quantity_good, 2) > 0) {
            $good = rtrim(rtrim((string) $result->quantity_good, '0'), '.');
            throw new InvalidProductionReceiptException(InvalidProductionReceiptException::MORE_THAN_PRODUCED, $good);
        }
    }
}
