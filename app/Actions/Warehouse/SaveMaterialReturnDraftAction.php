<?php

namespace App\Actions\Warehouse;

use App\Enums\DocumentStatus;
use App\Exceptions\InvalidMaterialReturnException;
use App\Models\MaterialReturn;
use App\Models\Product;
use App\Models\ProductionResult;
use App\Models\WorkOrderMaterial;
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
                    'work_order_material_id' => $row['material']->id,
                    'product_id' => $row['material']->product_id,
                    'quantity_expected' => $row['expected'],
                    'quantity' => $row['quantity'],
                ]);
            }

            return $return->load('items');
        });
    }

    /**
     * @param  array<int, string>  $returnedByProduct
     * @return Collection<int, array{material: WorkOrderMaterial, expected: string}>
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

        $resultMaterials = $result->materials;
        $materials = WorkOrderMaterial::query()
            ->whereIn('id', $resultMaterials->pluck('work_order_material_id'))
            ->with('product')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get()
            ->keyBy('id');

        $lines = $resultMaterials
            ->filter(fn ($row) => bccomp((string) $row->quantity_returned, '0', 2) > 0)
            ->map(fn ($row) => ['material' => $materials[$row->work_order_material_id], 'expected' => (string) $row->quantity_returned])
            ->values();

        if ($lines->isEmpty()) {
            throw new InvalidMaterialReturnException(InvalidMaterialReturnException::NOTHING_TO_RETURN);
        }

        foreach ($returnedByProduct as $productId => $quantity) {
            $expected = $lines->filter(fn (array $row) => $row['material']->product_id === (int) $productId)
                ->reduce(fn (string $carry, array $row) => bcadd($carry, $row['expected'], 2), '0');
            if (bccomp($quantity ?: '0', $expected, 2) > 0) {
                throw new InvalidMaterialReturnException(InvalidMaterialReturnException::MORE_THAN_ISSUED, Product::find($productId)?->product_name ?? "#{$productId}");
            }
        }

        return $lines;
    }

    /**
     * @param  Collection<int, array{material: WorkOrderMaterial, expected: string}>  $lines
     * @param  array<int, string>  $returnedByProduct
     * @return list<array{material: WorkOrderMaterial, expected: string, quantity: string}>
     */
    public static function allocate(Collection $lines, array $returnedByProduct): array
    {
        return $lines->map(fn (array $row) => [
            'material' => $row['material'],
            'expected' => $row['expected'],
            'quantity' => $returnedByProduct[$row['material']->product_id] ?? '0',
        ])->all();
    }
}
