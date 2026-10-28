<?php

namespace App\Support\Kpi;

use App\Enums\DocumentStatus;
use App\Enums\ProductType;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequisitionStatus;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceiptBatch;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseReturn;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PurchasingKpis
{
    public const MAX_SUPPLIER_SERIES = 5;

    public function __construct(
        public readonly CarbonInterface $start,
        public readonly CarbonInterface $end,
    ) {}

    public function forPeriod(CarbonInterface $start, CarbonInterface $end): self
    {
        return new self($start, $end);
    }

    /**
     * @return array{pending_approval: int, overdue: Collection<int, PurchaseOrder>, rejected: int, requisitions_submitted: int, draft_invoices: int, draft_returns: int}
     */
    public static function attention(CarbonInterface $today): array
    {
        return [
            'pending_approval' => PurchaseOrder::where('status', PurchaseOrderStatus::PendingApproval->value)->count(),
            'overdue' => PurchaseOrder::query()
                ->whereIn('status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::PartiallyReceived->value])
                ->whereDate('expected_delivery_date', '<', $today->toDateString())
                ->with('supplier')
                ->orderBy('expected_delivery_date')
                ->get(),
            'rejected' => PurchaseOrder::where('status', PurchaseOrderStatus::Rejected->value)->count(),
            'requisitions_submitted' => PurchaseRequisition::where('status', PurchaseRequisitionStatus::Submitted->value)->count(),
            'draft_invoices' => PurchaseInvoice::where('status', PurchaseInvoiceStatus::Draft->value)->count(),
            'draft_returns' => PurchaseReturn::where('status', DocumentStatus::Draft->value)->count(),
        ];
    }

    /**
     * Right-now stock status for every active raw material, against its reorder point
     * (the trigger to order again) and its own safety stock (used here as the width of
     * an early-warning band just above that trigger - "near" means stock is within one
     * safety-stock buffer of crossing the reorder point, not an arbitrary percentage).
     *
     * @return Collection<int, array{product: Product, current_stock: string, safety_stock: string, reorder_point: string, status: string, single_supplier_id: ?int}>
     */
    public static function materialStockStatus(): Collection
    {
        return Product::query()
            ->where('type', ProductType::RawMaterial->value)
            ->where('is_active', true)
            ->with('suppliers')
            ->orderBy('product_code')
            ->get()
            ->map(function (Product $product) {
                $nearCeiling = bcadd((string) $product->reorder_point, (string) $product->safety_stock, 2);
                $hasReorderPoint = bccomp((string) $product->reorder_point, '0', 2) > 0;

                $status = match (true) {
                    $hasReorderPoint && $product->isBelowReorderPoint() => 'below',
                    $hasReorderPoint && bccomp((string) $product->current_stock, $nearCeiling, 2) <= 0 => 'near',
                    default => 'ok',
                };

                return [
                    'product' => $product,
                    'current_stock' => (string) $product->current_stock,
                    'safety_stock' => (string) $product->safety_stock,
                    'reorder_point' => (string) $product->reorder_point,
                    'status' => $status,
                    // Only safe to deep-link straight into "create PO, this supplier,
                    // this item" when there's exactly one supplier to pick - with two
                    // or more, which one to buy from is a real decision for the user.
                    'single_supplier_id' => $product->suppliers->count() === 1 ? $product->suppliers->first()->id : null,
                ];
            })
            ->sortBy(fn (array $row) => match ($row['status']) {
                'below' => 0,
                'near' => 1,
                default => 2,
            })
            ->values();
    }

    public function spend(): string
    {
        return bcadd((string) $this->postedInvoices()->sum('subtotal'), '0', 2);
    }

    public function totalOrders(): int
    {
        return PurchaseOrder::query()
            ->whereBetween('order_date', [$this->start->toDateString(), $this->end->toDateString()])
            ->count();
    }

    /**
     * @return Collection<int, array{id: int, name: string, amount: string, count: int}>
     */
    public function supplierTotals(): Collection
    {
        return $this->postedInvoices()
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->groupBy('suppliers.id', 'suppliers.supplier_name')
            ->selectRaw('suppliers.id as id, suppliers.supplier_name as name, SUM(purchase_invoices.subtotal) as amount, COUNT(*) as count')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'amount' => bcadd((string) $row->amount, '0', 2), 'count' => (int) $row->count]);
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    public function seriesSuppliers(): Collection
    {
        return $this->supplierTotals()
            ->take(self::MAX_SUPPLIER_SERIES)
            ->map(fn ($row) => ['id' => $row['id'], 'name' => $row['name']])
            ->values();
    }

    /**
     * @param  Collection<int, array{id: int, name: string}>  $suppliers
     * @return array{months: list<string>, overall: list<float>, series: list<array{id: int, name: string, values: list<float>}>}
     */
    public function spendTrend(Collection $suppliers, int $months = 6): array
    {
        $labels = [];
        $overall = [];
        $perSupplier = $suppliers->mapWithKeys(fn ($s) => [$s['id'] => ['id' => $s['id'], 'name' => $s['name'], 'values' => []]])->all();

        foreach ($this->trailingMonths($months) as $month) {
            $labels[] = $month->start->translatedFormat('M Y');
            $totals = $month->supplierTotals()->keyBy('id');
            $overall[] = (float) $totals->sum(fn ($row) => (float) $row['amount']);

            foreach ($perSupplier as $id => $row) {
                $perSupplier[$id]['values'][] = isset($totals[$id]) ? (float) $totals[$id]['amount'] : 0.0;
            }
        }

        return ['months' => $labels, 'overall' => $overall, 'series' => array_values($perSupplier)];
    }

    /**
     * @return Collection<int, array{id: int, name: string, batches: int, on_time: int, rate: ?float}>
     */
    public function onTimeDelivery(): Collection
    {
        return PurchaseOrderReceiptBatch::query()
            ->whereBetween('received_at', [$this->start->copy()->startOfDay(), $this->end->copy()->endOfDay()])
            ->with('purchaseOrder.supplier')
            ->get()
            ->filter(fn (PurchaseOrderReceiptBatch $batch) => $batch->purchaseOrder->expected_delivery_date !== null)
            ->groupBy(fn (PurchaseOrderReceiptBatch $batch) => $batch->purchaseOrder->supplier_id)
            ->map(function (Collection $batches) {
                $supplier = $batches->first()->purchaseOrder->supplier;
                $onTime = $batches->filter(fn (PurchaseOrderReceiptBatch $batch) => $batch->received_at->toDateString() <= $batch->purchaseOrder->expected_delivery_date->toDateString())->count();

                return [
                    'id' => $supplier->id,
                    'name' => $supplier->supplier_name,
                    'batches' => $batches->count(),
                    'on_time' => $onTime,
                    'rate' => $batches->count() > 0 ? $onTime / $batches->count() * 100 : null,
                ];
            })
            ->sortByDesc('batches')
            ->values();
    }

    /**
     * @return Collection<int, array{id: int, name: string, goods: string, variance: string, rate: ?float}>
     */
    public function priceVariance(): Collection
    {
        return $this->postedInvoices()
            ->join('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->groupBy('suppliers.id', 'suppliers.supplier_name')
            ->selectRaw('suppliers.id as id, suppliers.supplier_name as name, SUM(purchase_invoices.goods_amount) as goods, SUM(purchase_invoices.price_variance) as variance')
            ->get()
            ->map(function ($row) {
                $goods = bcadd((string) $row->goods, '0', 2);
                $variance = bcadd((string) $row->variance, '0', 2);

                return [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                    'goods' => $goods,
                    'variance' => $variance,
                    'rate' => bccomp($goods, '0', 2) > 0 ? (float) $variance / (float) $goods * 100 : null,
                ];
            })
            ->sortByDesc(fn ($row) => abs((float) $row['variance']))
            ->values();
    }

    /**
     * @return list<self>
     */
    protected function trailingMonths(int $months): array
    {
        $periods = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = $this->start->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $periods[] = $this->forPeriod($start, $start->copy()->endOfMonth());
        }

        return $periods;
    }

    protected function postedInvoices(): Builder
    {
        return PurchaseInvoice::query()
            ->where('purchase_invoices.status', '!=', PurchaseInvoiceStatus::Draft->value)
            ->where('purchase_invoices.status', '!=', PurchaseInvoiceStatus::Cancelled->value)
            ->whereBetween('purchase_invoices.invoice_date', [$this->start->toDateString(), $this->end->toDateString()]);
    }
}
