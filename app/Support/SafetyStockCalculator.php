<?php

namespace App\Support;

use App\Enums\Direction;
use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\PurchaseOrderReceipt;
use App\Models\StockMovement;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Implements the max-min (Greene) safety stock formula:
 *
 *   Safety Stock = (D_max x L_max) - (D_avg x L_avg)
 *
 * D_max / D_avg = maximum / average daily usage of the item (quantity consumed per day).
 * L_max / L_avg = maximum / average lead time of the item, in days (time between placing
 * a purchase order and the goods actually arriving).
 *
 * Both D and L are measured in the same unit of time (days) so that D x L has a sensible
 * meaning: "how much of this item would be consumed if the worst-case wait happened".
 * Safety stock is then the gap between that worst case and the "normal" case
 * (average usage over the average wait) - the buffer needed to survive a bad month
 * without running out.
 *
 * Reorder Point = (D_avg x L_avg) + Safety Stock, i.e. "order again when stock falls to
 * the amount you'd normally use up while waiting for the next delivery, plus the buffer".
 */
class SafetyStockCalculator
{
    public function __construct(
        public readonly CarbonInterface $start,
        public readonly CarbonInterface $end,
    ) {}

    /**
     * @return Collection<int, array{product: Product, avg_usage: string, max_usage: string, avg_lead_time: ?float, max_lead_time: ?int, lead_time_samples: int, current_safety_stock: string, current_reorder_point: string, suggested_safety_stock: ?string, suggested_reorder_point: ?string, sufficient_data: bool}>
     */
    public function rows(): Collection
    {
        // Only raw materials are bought from suppliers and have a lead time to speak of -
        // the same scope MaterialShortage already uses for reorder-point logic.
        $products = Product::query()
            ->where('type', ProductType::RawMaterial->value)
            ->orderBy('product_code')
            ->get();
        $ids = $products->pluck('id');

        $usageByProduct = $this->dailyUsage($ids);
        $leadTimeByProduct = $this->leadTimeSamples($ids);

        return $products->map(function (Product $product) use ($usageByProduct, $leadTimeByProduct) {
            [$avgUsage, $maxUsage] = $this->usageStats($usageByProduct->get($product->id, collect()));
            $samples = $leadTimeByProduct->get($product->id, collect());
            $avgLeadTime = $samples->isNotEmpty() ? $samples->avg() : null;
            $maxLeadTime = $samples->isNotEmpty() ? $samples->max() : null;

            // Without at least one real delivery in the window there is no lead time to
            // base a suggestion on - showing a confident number (e.g. 0) would be worse
            // than admitting there isn't enough data yet.
            $sufficientData = $samples->isNotEmpty();

            $suggestedSafetyStock = null;
            $suggestedReorderPoint = null;
            if ($sufficientData) {
                $worstCase = bcmul($maxUsage, (string) $maxLeadTime, 2);
                $normalCase = bcmul($avgUsage, (string) $avgLeadTime, 2);
                // Safety stock can't be negative - if the "normal" case already covers the
                // worst case (a very stable item), zero buffer is needed, not a negative one.
                $suggestedSafetyStock = bccomp($worstCase, $normalCase, 2) > 0 ? bcsub($worstCase, $normalCase, 2) : '0.00';
                $suggestedReorderPoint = bcadd($normalCase, $suggestedSafetyStock, 2);

                // Round the *final* suggestion up to a whole unit, never down - a buffer
                // of "10.1 units" that gets truncated to 10 quietly erases the very margin
                // it exists to provide, so any leftover fraction rounds up, not to nearest.
                // Everything before this point (D/L and the SS/ROP maths itself) stays at
                // full bcmath precision so the rounding only happens once, at the end.
                $suggestedSafetyStock = self::ceilToUnit($suggestedSafetyStock);
                $suggestedReorderPoint = self::ceilToUnit($suggestedReorderPoint);
            }

            return [
                'product' => $product,
                'avg_usage' => $avgUsage,
                'max_usage' => $maxUsage,
                'avg_lead_time' => $avgLeadTime,
                'max_lead_time' => $maxLeadTime,
                'lead_time_samples' => $samples->count(),
                'current_safety_stock' => (string) $product->safety_stock,
                'current_reorder_point' => (string) $product->reorder_point,
                'suggested_safety_stock' => $suggestedSafetyStock,
                'suggested_reorder_point' => $suggestedReorderPoint,
                'sufficient_data' => $sufficientData,
            ];
        });
    }

    /**
     * Rounds a bcmath decimal string up to the next whole number - true ceiling, not
     * round-to-nearest, e.g. "10.10" becomes "11", same as "10.90" becomes "11", but an
     * exact "10.00" stays "10". bcmath's own scale parameter only truncates (chops the
     * decimals off), it never rounds, so a plain `bcadd($value, '0', 0)` would wrongly
     * turn "10.10" into "10" - the truncated value is computed first, then bumped by one
     * only if it actually lost a fractional part.
     */
    protected static function ceilToUnit(string $value): string
    {
        $truncated = bcadd($value, '0', 0);

        return bccomp($value, $truncated, 2) > 0 ? bcadd($truncated, '1', 0) : $truncated;
    }

    /**
     * Net daily usage per product: material issued to production (an OUT movement) minus
     * whatever of that same issue later came back unused (a Material Return, an IN
     * movement) - the same "Sigma IN - Sigma OUT" logic current_stock itself is built on,
     * just scoped to one product and one day at a time instead of all-time.
     *
     * @param  Collection<int, int>  $productIds
     * @return Collection<int, Collection<int, string>> product_id => [day => net usage]
     */
    protected function dailyUsage(Collection $productIds): Collection
    {
        $windowStart = $this->start->copy()->startOfDay();
        $windowEnd = $this->end->copy()->endOfDay();

        $movements = StockMovement::query()
            ->whereIn('product_id', $productIds)
            ->whereIn('type', [StockMovementType::ProductionInput->value, StockMovementType::MaterialReturn->value])
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->selectRaw('product_id, DATE(created_at) as day, direction, SUM(quantity) as qty')
            ->groupBy('product_id', 'day', 'direction')
            ->get();

        return $movements->groupBy('product_id')->map(function (Collection $rows) {
            return $rows->groupBy('day')->map(function (Collection $dayRows) {
                $issued = (string) ($dayRows->firstWhere('direction', Direction::Out->value)->qty ?? '0');
                $returned = (string) ($dayRows->firstWhere('direction', Direction::In->value)->qty ?? '0');
                $net = bcsub($issued, $returned, 2);

                // A day where more came back than went out nets to zero usage, not a
                // negative one - "usage" can't be less than nothing.
                return bccomp($net, '0', 2) > 0 ? $net : '0.00';
            });
        });
    }

    /**
     * @param  Collection<int, string>  $usageByDay
     * @return array{0: string, 1: string} [average daily usage, maximum daily usage]
     */
    protected function usageStats(Collection $usageByDay): array
    {
        $windowDays = max(1, $this->start->copy()->startOfDay()->diffInDays($this->end->copy()->endOfDay()) + 1);
        $total = $usageByDay->reduce(fn (string $carry, string $qty) => bcadd($carry, $qty, 2), '0.00');

        // The average spreads total usage across every day in the window, including idle
        // days with no movement at all - a material only used once a month should have a
        // low average, not the average of just its "active" days.
        $avg = bcdiv($total, (string) $windowDays, 2);
        $max = $usageByDay->reduce(fn (string $carry, string $qty) => bccomp($qty, $carry, 2) > 0 ? $qty : $carry, '0.00');

        return [$avg, $max];
    }

    /**
     * One lead-time sample per receipt event: the number of days between the purchase
     * order being placed and that batch actually arriving. A PO delivered in three
     * partial batches contributes three samples, one per batch - each one is a real
     * observation of "how long did we wait this time".
     *
     * @param  Collection<int, int>  $productIds
     * @return Collection<int, Collection<int, int>> product_id => [lead time in days, ...]
     */
    protected function leadTimeSamples(Collection $productIds): Collection
    {
        $windowStart = $this->start->copy()->startOfDay();
        $windowEnd = $this->end->copy()->endOfDay();

        $rows = PurchaseOrderReceipt::query()
            ->join('purchase_order_items', 'purchase_order_items.id', '=', 'purchase_order_receipts.purchase_order_item_id')
            ->join('purchase_order_receipt_batches', 'purchase_order_receipt_batches.id', '=', 'purchase_order_receipts.purchase_order_receipt_batch_id')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereIn('purchase_order_items.product_id', $productIds)
            ->whereBetween('purchase_order_receipt_batches.received_at', [$windowStart, $windowEnd])
            ->selectRaw('purchase_order_items.product_id as product_id, purchase_order_receipt_batches.received_at as received_at, purchase_orders.order_date as order_date')
            ->get();

        return $rows->groupBy('product_id')->map(function (Collection $group) {
            return $group->map(function ($row) {
                $receivedAt = Carbon::parse($row->received_at)->startOfDay();
                $orderedAt = Carbon::parse($row->order_date)->startOfDay();

                return max(0, $orderedAt->diffInDays($receivedAt));
            })->values();
        });
    }
}
