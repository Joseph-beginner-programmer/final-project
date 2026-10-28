<?php

namespace App\Support\Reports;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\PurchaseInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PurchaseRegisterReport
{
    public function __construct(
        public readonly CarbonInterface $start,
        public readonly CarbonInterface $end,
    ) {}

    /**
     * @return Collection<int, array{invoice: PurchaseInvoice, po_number: string, supplier: string, invoice_date: CarbonInterface, due_date: CarbonInterface, goods_amount: string, charges: string, tax_amount: string, total_amount: string, paid: string, balance: string, status: PurchaseInvoiceStatus}>
     */
    public function rows(): Collection
    {
        return PurchaseInvoice::query()
            ->where('status', '!=', PurchaseInvoiceStatus::Draft->value)
            ->whereBetween('invoice_date', [$this->start->toDateString(), $this->end->toDateString()])
            ->with(['supplier', 'purchaseOrder'])
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get()
            ->map(fn (PurchaseInvoice $invoice) => [
                'invoice' => $invoice,
                'po_number' => $invoice->purchaseOrder->po_number,
                'supplier' => $invoice->supplier->supplier_name,
                'invoice_date' => $invoice->invoice_date,
                'due_date' => $invoice->due_date,
                'goods_amount' => (string) $invoice->goods_amount,
                'charges' => bcadd((string) $invoice->capitalized_charges, (string) $invoice->other_charges, 2),
                'tax_amount' => (string) $invoice->tax_amount,
                'total_amount' => (string) $invoice->total_amount,
                'paid' => (string) $invoice->amount_paid,
                'balance' => $invoice->balance(),
                'status' => $invoice->status,
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{goods_amount: string, charges: string, tax_amount: string, total_amount: string, paid: string, balance: string}
     */
    public function sum(Collection $rows): array
    {
        $add = fn (string $field) => $rows->reduce(fn (string $carry, array $row) => bcadd($carry, (string) $row[$field], 2), '0');

        return [
            'goods_amount' => $add('goods_amount'),
            'charges' => $add('charges'),
            'tax_amount' => $add('tax_amount'),
            'total_amount' => $add('total_amount'),
            'paid' => $add('paid'),
            'balance' => $add('balance'),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array{supplier: string, invoices: int, goods_amount: string, charges: string, tax_amount: string, total_amount: string, paid: string, balance: string}>
     */
    public function supplierSummary(Collection $rows): Collection
    {
        return $rows->groupBy('supplier')
            ->map(fn (Collection $group) => ['supplier' => $group->first()['supplier'], 'invoices' => $group->count(), ...$this->sum($group)])
            ->sortByDesc(fn (array $row) => (float) $row['total_amount'])
            ->values();
    }

    /**
     * @return array{header: list<string>, rows: list<list<string>>}
     */
    public function excel(): array
    {
        $rows = $this->rows();
        $lines = $rows->map(fn (array $r) => [
            $r['invoice']->internal_number, $r['invoice']->invoice_number, $r['po_number'], $r['supplier'],
            $r['invoice_date']->format('Y-m-d'), $r['due_date']->format('Y-m-d'),
            Excel::number($r['goods_amount']), Excel::number($r['charges']), Excel::number($r['tax_amount']),
            Excel::number($r['total_amount']), Excel::number($r['paid']), Excel::number($r['balance']), $r['status']->label(),
        ])->all();
        $t = $this->sum($rows);
        $lines[] = [__('Total'), '', '', '', '', '', Excel::number($t['goods_amount']), Excel::number($t['charges']), Excel::number($t['tax_amount']), Excel::number($t['total_amount']), Excel::number($t['paid']), Excel::number($t['balance']), ''];

        return [
            'header' => [__('Internal no.'), __('Supplier invoice no.'), __('PO'), __('Supplier'), __('Invoice date'), __('Due date'), __('Goods'), __('Charges'), __('Tax'), __('Total'), __('Paid'), __('Balance'), __('Status')],
            'rows' => $lines,
        ];
    }
}
