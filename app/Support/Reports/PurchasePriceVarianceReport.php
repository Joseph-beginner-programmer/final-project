<?php

namespace App\Support\Reports;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\PurchaseInvoiceItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PurchasePriceVarianceReport
{
    public function __construct(
        public readonly CarbonInterface $start,
        public readonly CarbonInterface $end,
    ) {}

    /**
     * @return Collection<int, array{invoice_number: string, invoice_date: CarbonInterface, supplier: string, product: string, quantity: string, unit_price: string, lot_unit_cost: string, amount: string, charge_share: string, received_value: string, variance: string, rate: ?float}>
     */
    public function rows(): Collection
    {
        return PurchaseInvoiceItem::query()
            ->whereHas('purchaseInvoice', fn ($q) => $q
                ->where('status', '!=', PurchaseInvoiceStatus::Draft->value)
                ->where('status', '!=', PurchaseInvoiceStatus::Cancelled->value)
                ->whereBetween('invoice_date', [$this->start->toDateString(), $this->end->toDateString()]))
            ->with(['purchaseInvoice.supplier', 'product'])
            ->get()
            ->map(function (PurchaseInvoiceItem $item) {
                $invoice = $item->purchaseInvoice;
                $chargeShare = bccomp((string) $invoice->goods_amount, '0', 2) > 0
                    ? bcdiv(bcmul((string) $invoice->capitalized_charges, (string) $item->amount, 6), (string) $invoice->goods_amount, 2)
                    : '0';
                $landedAmount = bcadd((string) $item->amount, $chargeShare, 2);
                $variance = bcsub($landedAmount, (string) $item->received_value, 2);
                $lotUnitCost = bccomp((string) $item->quantity, '0', 2) > 0 ? bcdiv((string) $item->received_value, (string) $item->quantity, 6) : '0';

                return [
                    'invoice_number' => $invoice->internal_number,
                    'invoice_date' => $invoice->invoice_date,
                    'supplier' => $invoice->supplier->supplier_name,
                    'product' => $item->product->product_name,
                    'quantity' => (string) $item->quantity,
                    'unit_price' => (string) $item->unit_price,
                    'lot_unit_cost' => $lotUnitCost,
                    'amount' => (string) $item->amount,
                    'charge_share' => $chargeShare,
                    'received_value' => (string) $item->received_value,
                    'variance' => $variance,
                    'rate' => bccomp((string) $item->received_value, '0', 2) > 0 ? (float) $variance / (float) $item->received_value * 100 : null,
                ];
            })
            ->sortBy(fn (array $row) => $row['invoice_date'])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{amount: string, charge_share: string, received_value: string, variance: string}
     */
    public function sum(Collection $rows): array
    {
        $add = fn (string $field) => $rows->reduce(fn (string $carry, array $row) => bcadd($carry, (string) $row[$field], 2), '0');

        return ['amount' => $add('amount'), 'charge_share' => $add('charge_share'), 'received_value' => $add('received_value'), 'variance' => $add('variance')];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array{supplier: string, lines: int, amount: string, received_value: string, variance: string, rate: ?float}>
     */
    public function supplierSummary(Collection $rows): Collection
    {
        return $rows->groupBy('supplier')
            ->map(function (Collection $group) {
                $totals = $this->sum($group);

                return [
                    'supplier' => $group->first()['supplier'],
                    'lines' => $group->count(),
                    ...$totals,
                    'rate' => bccomp($totals['received_value'], '0', 2) > 0 ? (float) $totals['variance'] / (float) $totals['received_value'] * 100 : null,
                ];
            })
            ->sortByDesc(fn (array $row) => abs((float) $row['variance']))
            ->values();
    }

    /**
     * @return array{header: list<string>, rows: list<list<string>>}
     */
    public function excel(): array
    {
        $rows = $this->rows();
        $lines = $rows->map(fn (array $r) => [
            $r['invoice_number'], $r['invoice_date']->format('Y-m-d'), $r['supplier'], $r['product'],
            Excel::number($r['quantity']), Excel::number($r['unit_price']), Excel::number($r['lot_unit_cost']),
            Excel::number($r['amount']), Excel::number($r['charge_share']), Excel::number($r['received_value']), Excel::number($r['variance']),
        ])->all();
        $t = $this->sum($rows);
        $lines[] = [__('Total'), '', '', '', '', '', '', Excel::number($t['amount']), Excel::number($t['charge_share']), Excel::number($t['received_value']), Excel::number($t['variance'])];

        return [
            'header' => [__('Invoice'), __('Date'), __('Supplier'), __('Product'), __('Quantity'), __('Invoice unit price'), __('Lot unit cost'), __('Invoice amount'), __('Charges'), __('Lot value'), __('Variance')],
            'rows' => $lines,
        ];
    }
}
