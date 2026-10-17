<?php

namespace App\Support;

use App\Models\InventoryLot;

class ProductCost
{
    /**
     * @param  iterable<int>  $productIds
     * @return array<int, array{average: ?string, stock: string, last: ?string}>
     */
    public static function forProducts(iterable $productIds): array
    {
        $ids = collect($productIds)->values()->all();
        $result = [];

        $totals = InventoryLot::query()
            ->whereIn('product_id', $ids)
            ->where('quantity_remaining', '>', 0)
            ->selectRaw('product_id, SUM(quantity_remaining) as quantity, SUM(value_remaining) as value')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $last = InventoryLot::query()
            ->whereIn('product_id', $ids)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get(['product_id', 'unit_cost'])
            ->unique('product_id')
            ->keyBy('product_id');

        foreach ($ids as $id) {
            $row = $totals->get($id);
            $quantity = $row ? (string) $row->quantity : '0';
            $result[$id] = [
                'average' => bccomp($quantity, '0', 2) > 0 ? self::round(bcdiv((string) $row->value, $quantity, 4)) : null,
                'stock' => $quantity,
                'last' => $last->has($id) ? (string) $last->get($id)->unit_cost : null,
            ];
        }

        return $result;
    }

    /**
     * @return array{lots: list<array{lot_number: string, received_at: \Carbon\CarbonInterface, quantity: string, unit_cost: string}>, covered: string, uncovered: string, average: ?string}
     */
    public static function fifoPreview(int $productId, string $quantity, string $skip = '0'): array
    {
        $lots = [];
        $toSkip = $skip;
        $needed = $quantity;
        $value = '0';
        $covered = '0';

        foreach (InventoryLot::availableFifo($productId)->get() as $lot) {
            if (bccomp($needed, '0', 2) <= 0) {
                break;
            }

            $free = (string) $lot->quantity_remaining;
            if (bccomp($toSkip, '0', 2) > 0) {
                $skipped = bccomp($toSkip, $free, 2) >= 0 ? $free : $toSkip;
                $toSkip = bcsub($toSkip, $skipped, 2);
                $free = bcsub($free, $skipped, 2);
            }
            if (bccomp($free, '0', 2) <= 0) {
                continue;
            }

            $take = bccomp($free, $needed, 2) <= 0 ? $free : $needed;
            $lots[] = [
                'lot_number' => $lot->lot_number,
                'received_at' => $lot->received_at,
                'quantity' => $take,
                'unit_cost' => (string) $lot->unit_cost,
            ];
            $value = bcadd($value, bcmul($take, (string) $lot->unit_cost, 4), 4);
            $covered = bcadd($covered, $take, 2);
            $needed = bcsub($needed, $take, 2);
        }

        return [
            'lots' => $lots,
            'covered' => $covered,
            'uncovered' => bccomp($needed, '0', 2) > 0 ? $needed : '0',
            'average' => bccomp($covered, '0', 2) > 0 ? self::round(bcdiv($value, $covered, 4)) : null,
        ];
    }

    public static function margin(string $price, string $cost): ?string
    {
        if (bccomp($price, '0', 2) <= 0) {
            return null;
        }

        return self::round(bcdiv(bcmul(bcsub($price, $cost, 4), '100', 4), $price, 4), 1);
    }

    private static function round(string $value, int $scale = 2): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($value, '0', 4) >= 0
            ? bcadd($value, $half, $scale)
            : bcsub($value, $half, $scale);
    }
}
