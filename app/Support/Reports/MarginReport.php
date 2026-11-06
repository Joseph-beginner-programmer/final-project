<?php

namespace App\Support\Reports;

use App\Enums\DocumentStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MarginReport
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    /**
     * @return Collection<int, array{name: string, quantity: ?float, revenue: float, cost: float, profit: float, margin: ?float}>
     */
    public function byProduct(): Collection
    {
        return $this->rows('products.id', 'products.product_name', withQuantity: true);
    }

    /**
     * @return Collection<int, array{name: string, quantity: ?float, revenue: float, cost: float, profit: float, margin: ?float}>
     */
    public function byCustomer(): Collection
    {
        return $this->rows('customers.id', 'customers.customer_name', withQuantity: false);
    }

    /**
     * @return Collection<int, array{name: string, quantity: ?float, revenue: float, cost: float, profit: float, margin: ?float}>
     */
    protected function rows(string $groupColumn, string $nameColumn, bool $withQuantity): Collection
    {
        return DB::table('sales_delivery_items')
            ->join('sales_deliveries', 'sales_deliveries.id', '=', 'sales_delivery_items.sales_delivery_id')
            ->join('sales_order_items', 'sales_order_items.id', '=', 'sales_delivery_items.sales_order_item_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_deliveries.sales_order_id')
            ->join('products', 'products.id', '=', 'sales_delivery_items.product_id')
            ->join('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->where('sales_deliveries.status', DocumentStatus::Posted->value)
            ->whereBetween('sales_deliveries.delivered_at', [$this->start->copy()->startOfDay(), $this->end->copy()->endOfDay()])
            ->groupBy($groupColumn, $nameColumn)
            ->selectRaw($nameColumn.' as name, SUM(sales_delivery_items.quantity) as quantity, SUM(sales_delivery_items.quantity * sales_order_items.unit_price * (1 - sales_orders.discount_percent / 100)) as revenue, SUM(sales_delivery_items.cogs_total) as cost')
            ->get()
            ->map(function ($row) use ($withQuantity) {
                $revenue = round((float) $row->revenue, 2);
                $cost = round((float) $row->cost, 2);

                return [
                    'name' => $row->name,
                    'quantity' => $withQuantity ? (float) $row->quantity : null,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $revenue - $cost,
                    'margin' => $revenue > 0 ? ($revenue - $cost) / $revenue * 100 : null,
                ];
            })
            ->sortByDesc('profit')
            ->values();
    }

    /**
     * @param  Collection<int, array{name: string, quantity: ?float, revenue: float, cost: float, profit: float, margin: ?float}>  $rows
     * @return list<list<string>>
     */
    public static function excel(Collection $rows, bool $withQuantity): array
    {
        return $rows->map(fn (array $row) => array_values(array_filter([
            $row['name'],
            $withQuantity ? Excel::number($row['quantity']) : null,
            Excel::number($row['revenue']),
            Excel::number($row['cost']),
            Excel::number($row['profit']),
            $row['margin'] !== null ? Excel::number($row['margin'], 1).'%' : '',
        ], fn ($v) => $v !== null)))->all();
    }
}
