<?php

namespace App\Actions\Warehouse;

use App\Enums\DocumentStatus;
use App\Exceptions\InvalidMaterialReturnException;
use App\Models\MaterialIssueItem;
use App\Models\MaterialReturn;
use App\Models\Product;
use App\Models\ProductionResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveMaterialReturnDraftAction
{
    /**
     * @param  array<int, string>  $returnedByProduct  product id => quantity actually received back
     */
    public function handle(ProductionResult $result, array $returnedByProduct, int $userId, ?MaterialReturn $return = null): MaterialReturn
    {
        $return?->ensureIsDraft();

        $lines = self::checkReturnable($result, $returnedByProduct, $return);

        return DB::transaction(function () use ($result, $returnedByProduct, $userId, $return, $lines) {
            if (! $return) {
                $return = new MaterialReturn([
                    'work_order_id' => $result->work_order_id,
                    'production_result_id' => $result->id,
                    'created_by' => $userId,
                ]);
                $return->status = DocumentStatus::Draft;
                $return->return_number = (string) Str::uuid();
                $return->save();

                $return->return_number = sprintf('MR-%s-%06d', $return->created_at->format('Y'), $return->id);
                $return->save();
            }

            $return->items()->delete();
            foreach (self::allocate($lines, $returnedByProduct) as $row) {
                $return->items()->create([
                    'material_issue_item_id' => $row['line']->id,
                    'product_id' => $row['line']->product_id,
                    'quantity_expected' => $row['expected'],
                    'quantity' => $row['quantity'],
                ]);
            }

            return $return->load('items');
        });
    }

    /**
     * @param  array<int, string>  $returnedByProduct
     * @return Collection<int, MaterialIssueItem>
     */
    public static function checkReturnable(ProductionResult $result, array $returnedByProduct, ?MaterialReturn $current = null, bool $lock = false): Collection
    {
        if ($result->status !== DocumentStatus::Posted) {
            throw new InvalidMaterialReturnException(InvalidMaterialReturnException::RESULT_NOT_POSTED);
        }

        $other = $result->materialReturns()
            ->where('status', '!=', DocumentStatus::Cancelled->value)
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->first();
        if ($other) {
            throw new InvalidMaterialReturnException(InvalidMaterialReturnException::ALREADY_RETURNED, $other->return_number);
        }

        $lines = $result->workOrder->postedIssueItems($lock);

        $expected = $lines->reduce(fn (string $carry, MaterialIssueItem $line) => bcadd($carry, bcsub((string) $line->quantity, (string) $line->quantity_consumed, 2), 2), '0');
        if (bccomp($expected, '0', 2) <= 0) {
            throw new InvalidMaterialReturnException(InvalidMaterialReturnException::NOTHING_TO_RETURN);
        }

        foreach ($returnedByProduct as $productId => $quantity) {
            $issued = $lines->where('product_id', $productId)->reduce(fn (string $carry, MaterialIssueItem $line) => bcadd($carry, (string) $line->quantity, 2), '0');
            if (bccomp($quantity ?: '0', $issued, 2) > 0) {
                throw new InvalidMaterialReturnException(InvalidMaterialReturnException::MORE_THAN_ISSUED, Product::find($productId)?->product_name ?? "#{$productId}");
            }
        }

        return $lines;
    }

    /**
     * @param  Collection<int, MaterialIssueItem>  $lines  ordered oldest issue first
     * @param  array<int, string>  $returnedByProduct
     * @return list<array{line: MaterialIssueItem, expected: string, quantity: string}>
     */
    public static function allocate(Collection $lines, array $returnedByProduct): array
    {
        $stillToPlace = array_map(fn ($quantity) => $quantity ?: '0', $returnedByProduct);
        $rows = [];

        foreach ($lines->reverse() as $line) {
            $expected = bcsub((string) $line->quantity, (string) $line->quantity_consumed, 2);
            $left = $stillToPlace[$line->product_id] ?? '0';
            $take = bccomp((string) $line->quantity, $left, 2) <= 0 ? (string) $line->quantity : $left;
            $stillToPlace[$line->product_id] = bcsub($left, $take, 2);

            if (bccomp($expected, '0', 2) > 0 || bccomp($take, '0', 2) > 0) {
                $rows[] = ['line' => $line, 'expected' => $expected, 'quantity' => $take];
            }
        }

        return $rows;
    }
}
