<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseRequisitionStatus;
use App\Exceptions\InvalidPurchaseRequisitionException;
use App\Models\Product;
use App\Models\PurchaseRequisition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePurchaseRequisitionAction
{
    /**
     * @param  list<array{product_id: int, quantity: string}>  $lines
     */
    public function handle(array $lines, string $neededBy, int $userId): PurchaseRequisition
    {
        if (Carbon::parse($neededBy)->startOfDay()->lt(now()->startOfDay())) {
            throw new InvalidPurchaseRequisitionException(InvalidPurchaseRequisitionException::NEEDED_BY_PAST);
        }

        $lines = collect($lines)
            ->filter(fn (array $line) => filled($line['product_id']) && is_numeric($line['quantity']) && bccomp((string) $line['quantity'], '0', 2) > 0)
            ->groupBy('product_id')
            ->map(fn ($rows, $productId) => [
                'product_id' => (int) $productId,
                'quantity' => $rows->reduce(fn (string $carry, array $row) => bcadd($carry, (string) $row['quantity'], 2), '0'),
            ])
            ->values()
            ->all();

        if ($lines === []) {
            throw new InvalidPurchaseRequisitionException(InvalidPurchaseRequisitionException::NO_LINES);
        }

        foreach ($lines as $line) {
            $product = Product::findOrFail($line['product_id']);
            if (! $product->type->isPurchasable()) {
                throw new InvalidPurchaseRequisitionException(InvalidPurchaseRequisitionException::NOT_PURCHASABLE, $product->product_name);
            }
        }

        return DB::transaction(function () use ($lines, $neededBy, $userId) {
            $requisition = new PurchaseRequisition([
                'needed_by' => $neededBy,
                'created_by' => $userId,
            ]);
            $requisition->status = PurchaseRequisitionStatus::Submitted;
            $requisition->requisition_number = (string) Str::uuid();
            $requisition->save();
            $requisition->requisition_number = sprintf('PR-%s-%06d', $requisition->created_at->format('Y'), $requisition->id);
            $requisition->save();

            foreach ($lines as $line) {
                $requisition->items()->create($line);
            }

            return $requisition->load('items');
        });
    }
}
