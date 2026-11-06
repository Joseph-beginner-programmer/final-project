<?php

namespace App\Support\Reports;

use App\Enums\DocumentStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\SalesInvoiceStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AgingReport
{
    protected ?Collection $openCache = null;

    /**
     * @param  list<string>  $statuses
     */
    public function __construct(
        protected readonly string $invoices,
        protected readonly string $payments,
        protected readonly string $paymentKey,
        protected readonly string $parties,
        protected readonly string $partyKey,
        protected readonly string $partyName,
        protected readonly array $statuses,
        public readonly Carbon $asOf,
    ) {}

    public static function receivables(Carbon $asOf): self
    {
        return new self('sales_invoices', 'sales_payments', 'sales_invoice_id', 'customers', 'customer_id', 'customer_name', [
            SalesInvoiceStatus::Unpaid->value, SalesInvoiceStatus::PartiallyPaid->value, SalesInvoiceStatus::Paid->value,
        ], $asOf);
    }

    public static function payables(Carbon $asOf): self
    {
        return new self('purchase_invoices', 'purchase_payments', 'purchase_invoice_id', 'suppliers', 'supplier_id', 'supplier_name', [
            PurchaseInvoiceStatus::Unpaid->value, PurchaseInvoiceStatus::PartiallyPaid->value, PurchaseInvoiceStatus::Paid->value,
        ], $asOf);
    }

    /**
     * @return list<string>
     */
    public static function agingKeys(): array
    {
        return ['current', '1_30', '31_60', '61_90', 'over_90'];
    }

    public static function bucketFor(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => '1_30',
            $daysOverdue <= 60 => '31_60',
            $daysOverdue <= 90 => '61_90',
            default => 'over_90',
        };
    }

    protected function openInvoices(): Collection
    {
        if ($this->openCache !== null) {
            return $this->openCache;
        }

        $paid = DB::table($this->payments)
            ->where('status', DocumentStatus::Posted->value)
            ->whereDate('payment_date', '<=', $this->asOf->toDateString())
            ->groupBy($this->paymentKey)
            ->selectRaw("{$this->paymentKey} as invoice_id, SUM(amount) as paid")
            ->pluck('paid', 'invoice_id');

        return $this->openCache = DB::table($this->invoices)
            ->join($this->parties, "{$this->parties}.id", '=', "{$this->invoices}.{$this->partyKey}")
            ->whereIn("{$this->invoices}.status", $this->statuses)
            ->whereDate("{$this->invoices}.invoice_date", '<=', $this->asOf->toDateString())
            ->get(["{$this->invoices}.id", "{$this->invoices}.total_amount", "{$this->invoices}.due_date", "{$this->parties}.{$this->partyName} as party"])
            ->map(function ($row) use ($paid) {
                $balance = round((float) $row->total_amount - (float) ($paid[$row->id] ?? 0), 2);
                $days = (int) Carbon::parse($row->due_date)->startOfDay()->diffInDays($this->asOf->copy()->startOfDay(), false);

                return ['party' => $row->party, 'balance' => $balance, 'days' => $days, 'bucket' => self::bucketFor($days)];
            })
            ->filter(fn (array $row) => $row['balance'] > 0.004);
    }

    /**
     * @return array{buckets: list<array{key: string, amount: float, count: int}>, total: float, overdue: float, overdue_count: int, top: Collection<int, array{name: string, amount: float, days: int}>}
     */
    public function summary(): array
    {
        $open = $this->openInvoices();

        $buckets = array_map(fn (string $key) => [
            'key' => $key,
            'amount' => round($open->where('bucket', $key)->sum('balance'), 2),
            'count' => $open->where('bucket', $key)->count(),
        ], self::agingKeys());

        $overdue = $open->where('days', '>', 0);

        return [
            'buckets' => $buckets,
            'total' => round($open->sum('balance'), 2),
            'overdue' => round($overdue->sum('balance'), 2),
            'overdue_count' => $overdue->count(),
            'top' => $overdue->groupBy('party')
                ->map(fn (Collection $rows, string $party) => ['name' => $party, 'amount' => round($rows->sum('balance'), 2), 'days' => $rows->max('days')])
                ->sortByDesc('amount')
                ->take(3)
                ->values(),
        ];
    }

    /**
     * @return Collection<int, array{name: string, buckets: array<string, float>, total: float, max_days: int}>
     */
    public function rows(): Collection
    {
        return $this->openInvoices()->groupBy('party')
            ->map(function (Collection $rows, string $party) {
                $buckets = [];
                foreach (self::agingKeys() as $key) {
                    $buckets[$key] = round($rows->where('bucket', $key)->sum('balance'), 2);
                }

                return [
                    'name' => $party,
                    'buckets' => $buckets,
                    'total' => round($rows->sum('balance'), 2),
                    'max_days' => $rows->max('days'),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    /**
     * @param  Collection<int, array{name: string, buckets: array<string, float>, total: float, max_days: int}>  $rows
     * @return list<list<string>>
     */
    public static function excel(Collection $rows): array
    {
        return $rows->map(fn (array $row) => [
            $row['name'],
            Excel::number($row['buckets']['current']),
            Excel::number($row['buckets']['1_30']),
            Excel::number($row['buckets']['31_60']),
            Excel::number($row['buckets']['61_90']),
            Excel::number($row['buckets']['over_90']),
            Excel::number($row['total']),
        ])->all();
    }
}
