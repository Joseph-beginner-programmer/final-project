<?php

namespace App\Support\Reports;

use App\Enums\Direction;
use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class InventoryReport
{
    public function __construct(
        public readonly CarbonInterface $start,
        public readonly CarbonInterface $end,
        public readonly ?ProductType $type = null,
    ) {}

    /**
     * @return list<ProductType>
     */
    public function types(): array
    {
        return $this->type ? [$this->type] : self::allTypes();
    }

    /**
     * @return list<ProductType>
     */
    public static function allTypes(): array
    {
        return [ProductType::RawMaterial, ProductType::Wip, ProductType::FinishedGoods];
    }

    /**
     * @return Collection<int, array{product: Product, opening_qty: string, opening_value: string, in_qty: string, in_value: string, out_qty: string, out_value: string, closing_qty: string, closing_value: string, breakdown: Collection<int, array{type: StockMovementType, direction: Direction, qty: string, value: string}>}>
     */
    public function rows(): Collection
    {
        $products = Product::query()
            ->whereIn('type', array_map(fn (ProductType $t) => $t->value, $this->types()))
            ->orderByRaw('FIELD(type, '.implode(', ', array_map(fn (ProductType $t) => "'".$t->value."'", self::allTypes())).')')
            ->orderBy('product_code')
            ->get();
        $ids = $products->pluck('id');
        $periodStart = $this->start->copy()->startOfDay();
        $periodEnd = $this->end->copy()->endOfDay();

        $opening = StockMovement::query()
            ->whereIn('product_id', $ids)
            ->where('created_at', '<', $periodStart)
            ->groupBy('product_id', 'direction')
            ->selectRaw('product_id, direction, SUM(quantity) as qty, SUM(total_value) as value')
            ->get()
            ->groupBy('product_id');

        $movements = StockMovement::query()
            ->whereIn('product_id', $ids)
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->groupBy('product_id', 'direction', 'type')
            ->selectRaw('product_id, direction, type, SUM(quantity) as qty, SUM(total_value) as value')
            ->get()
            ->groupBy('product_id');

        return $products
            ->map(function (Product $product) use ($opening, $movements) {
                $openingQty = '0';
                $openingValue = '0';
                foreach ($opening[$product->id] ?? [] as $row) {
                    $sign = $row->direction === Direction::In ? 1 : -1;
                    $openingQty = $sign > 0 ? bcadd($openingQty, (string) $row->qty, 2) : bcsub($openingQty, (string) $row->qty, 2);
                    $openingValue = $sign > 0 ? bcadd($openingValue, (string) $row->value, 2) : bcsub($openingValue, (string) $row->value, 2);
                }

                $breakdown = collect($movements[$product->id] ?? [])->map(fn ($row) => [
                    'type' => $row->type,
                    'direction' => $row->direction,
                    'qty' => bcadd((string) $row->qty, '0', 2),
                    'value' => bcadd((string) $row->value, '0', 2),
                ])->sortBy(fn ($row) => ($row['direction'] === Direction::In ? '0' : '1').$row['type']->value)->values();

                $sum = fn (Direction $direction, string $field) => $breakdown->where('direction', $direction)->reduce(fn (string $carry, $row) => bcadd($carry, $row[$field], 2), '0');
                $inQty = $sum(Direction::In, 'qty');
                $inValue = $sum(Direction::In, 'value');
                $outQty = $sum(Direction::Out, 'qty');
                $outValue = $sum(Direction::Out, 'value');

                return [
                    'product' => $product,
                    'opening_qty' => $openingQty,
                    'opening_value' => $openingValue,
                    'in_qty' => $inQty,
                    'in_value' => $inValue,
                    'out_qty' => $outQty,
                    'out_value' => $outValue,
                    'closing_qty' => bcsub(bcadd($openingQty, $inQty, 2), $outQty, 2),
                    'closing_value' => bcsub(bcadd($openingValue, $inValue, 2), $outValue, 2),
                    'breakdown' => $breakdown,
                ];
            })
            ->filter(fn ($row) => $row['product']->is_active || bccomp($row['opening_qty'], '0', 2) !== 0 || $row['breakdown']->isNotEmpty())
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{opening_value: string, in_value: string, out_value: string, closing_value: string}
     */
    public function valueTotals(Collection $rows): array
    {
        $add = fn (string $field) => $rows->reduce(fn (string $carry, $row) => bcadd($carry, $row[$field], 2), '0');

        return [
            'opening_value' => $add('opening_value'),
            'in_value' => $add('in_value'),
            'out_value' => $add('out_value'),
            'closing_value' => $add('closing_value'),
        ];
    }

    /**
     * @return array{header: list<string>, rows: list<list<string>>}
     */
    public function excel(bool $withValue): array
    {
        $rows = $this->rows();
        $header = [__('Code'), __('Item'), __('Type'), __('Unit'), __('Opening'), __('In'), __('Out'), __('Closing')];
        if ($withValue) {
            $header = [...$header, __('Opening (Rp)'), __('In (Rp)'), __('Out (Rp)'), __('Closing (Rp)')];
        }

        $lines = $rows->map(function ($r) use ($withValue) {
            $line = [
                $r['product']->product_code, $r['product']->product_name, $r['product']->type->label(), $r['product']->unit_of_measure,
                Excel::number($r['opening_qty']), Excel::number($r['in_qty']), Excel::number($r['out_qty']), Excel::number($r['closing_qty']),
            ];

            return $withValue ? [...$line, Excel::number($r['opening_value']), Excel::number($r['in_value']), Excel::number($r['out_value']), Excel::number($r['closing_value'])] : $line;
        })->all();

        if ($withValue) {
            $t = $this->valueTotals($rows);
            $lines[] = [__('Total'), '', '', '', '', '', '', '', Excel::number($t['opening_value']), Excel::number($t['in_value']), Excel::number($t['out_value']), Excel::number($t['closing_value'])];
        }

        return ['header' => $header, 'rows' => $lines];
    }
}
