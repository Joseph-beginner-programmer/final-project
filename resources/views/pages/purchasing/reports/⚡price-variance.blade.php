<?php

use App\Support\Reports\PurchasePriceVarianceReport;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Purchase Price Variance Report')] class extends Component {
    #[Url]
    public string $period = '';

    public function mount(): void
    {
        Gate::authorize('reports.purchasing');
        $this->normalize();
    }

    public function updated(): void
    {
        $this->normalize();
    }

    protected function normalize(): void
    {
        $this->period = ReportPeriod::normalize($this->period);
    }

    public function shiftPeriod(int $months): void
    {
        $this->period = ReportPeriod::start($this->period)->addMonthsNoOverflow($months)->format('Y-m');
    }

    #[Computed]
    public function report(): PurchasePriceVarianceReport
    {
        return new PurchasePriceVarianceReport(ReportPeriod::start($this->period), ReportPeriod::end($this->period));
    }

    #[Computed]
    public function rows(): Collection
    {
        return $this->report->rows();
    }

    #[Computed]
    public function totals(): array
    {
        return $this->report->sum($this->rows);
    }

    #[Computed]
    public function suppliers(): Collection
    {
        return $this->report->supplierSummary($this->rows);
    }

    public function rupiah(string|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return ((float) $value < 0 ? '−Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.');
    }

    public function number(string|float $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    public function percent(?float $value): string
    {
        return $value === null ? '—' : (($value > 0 ? '+' : '').number_format($value, 1, ',', '.').'%');
    }
}; ?>

@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 whitespace-nowrap')
@php($num = 'py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap')
@php($ink = 'text-zinc-800 dark:text-zinc-200')
@php($exportParams = ['period' => $period])
@php($t = $this->totals)
@php($overallRate = bccomp($t['received_value'], '0', 2) > 0 ? (float) $t['variance'] / (float) $t['received_value'] * 100 : null)

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route(auth()->user()->role->reportsRoute())" wire:navigate>{{ __('Reports') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Purchase Price Variance Report') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Purchase Price Variance Report') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Invoiced price vs. the lot value at receipt (Selisih Harga Pembelian), per invoice line.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="filled" icon="document-arrow-down" :href="route('purchasing.reports.price-variance.export', ['format' => 'pdf', ...$exportParams])" class="active:scale-[0.95]">PDF</flux:button>
            <flux:button size="sm" variant="filled" icon="table-cells" :href="route('purchasing.reports.price-variance.export', ['format' => 'excel', ...$exportParams])" class="active:scale-[0.95]">Excel</flux:button>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400 me-1">{{ __('Period') }}</span>
        <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="shiftPeriod(-1)" :aria-label="__('Previous month')" class="active:scale-[0.95]" />
        <div class="w-44">
            <flux:input size="sm" type="month" wire:model.live="period" :aria-label="__('Period')" />
        </div>
        <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="shiftPeriod(1)" :aria-label="__('Next month')" class="active:scale-[0.95]" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="period,shiftPeriod" class="transition-opacity">
        <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ([
                [__('Invoice lines'), $this->rows->count(), null],
                [__('Invoiced amount'), $this->rupiah($t['amount']), null],
                [__('Lot value at receipt'), $this->rupiah($t['received_value']), null],
                [__('Variance'), $this->rupiah($t['variance']), $this->percent($overallRate)],
            ] as [$label, $value, $note])
                <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-4">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                    <p class="mt-2 text-xl font-semibold text-zinc-900 dark:text-white">{{ $value }}</p>
                    @if ($note)
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $note }}</p>
                    @endif
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Positive variance = invoiced above the receipt lot value (debited to Selisih Harga Pembelian); negative = invoiced below (credited). Charges is this invoice\'s share of landed cost already folded into the lot at receipt, added to the invoice price before comparing to the lot cost — a line with charges but no real price mismatch shows zero variance.') }}</p>

        <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Invoice lines') }}</h2>
        <div class="mt-3 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full min-w-[65rem] text-xs border-collapse">
                <thead>
                    <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="{{ $th }}">{{ __('Invoice') }}</th>
                        <th class="{{ $th }}">{{ __('Supplier') }}</th>
                        <th class="{{ $th }}">{{ __('Product') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Quantity') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Invoice price') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Lot unit cost') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Charges') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Variance') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Rate') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $r)
                        @php($over = (float) $r['variance'] > 0)
                        <tr class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03]">
                            <td class="py-2.5 px-3">
                                <span class="font-data text-zinc-700 dark:text-zinc-300">{{ $r['invoice_number'] }}</span>
                                <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $r['invoice_date']->translatedFormat('d M Y') }}</p>
                            </td>
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ $r['supplier'] }}</td>
                            <td class="py-2.5 px-3 text-zinc-700 dark:text-zinc-300">{{ $r['product'] }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->number($r['quantity']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($r['unit_price']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($r['lot_unit_cost']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($r['charge_share']) }}</td>
                            <td class="{{ $num }} font-semibold {{ $over ? 'text-red-700 dark:text-red-400' : ((float) $r['variance'] < 0 ? 'text-green-700 dark:text-green-400' : $ink) }}">{{ $over ? '+' : '' }}{{ $this->rupiah($r['variance']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->percent($r['rate']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No invoice lines in this period.') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($this->rows->isNotEmpty())
                    <tfoot>
                        <tr class="border-t-2 border-zinc-300 dark:border-white/20 bg-zinc-50 dark:bg-white/5 font-semibold">
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white" colspan="3">{{ __('Total') }}</td>
                            <td></td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['amount']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['received_value']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['charge_share']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ (float) $t['variance'] > 0 ? '+' : '' }}{{ $this->rupiah($t['variance']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->percent($overallRate) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Summary per supplier') }}</h2>
        <div class="mt-3 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full min-w-[45rem] text-xs border-collapse">
                <thead>
                    <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="{{ $th }}">{{ __('Supplier') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Lines') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Invoiced amount') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Lot value') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Variance') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Rate') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->suppliers as $s)
                        <tr class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ $s['supplier'] }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $s['lines'] }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($s['amount']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($s['received_value']) }}</td>
                            <td class="{{ $num }} font-semibold {{ (float) $s['variance'] > 0 ? 'text-red-700 dark:text-red-400' : ((float) $s['variance'] < 0 ? 'text-green-700 dark:text-green-400' : $ink) }}">{{ (float) $s['variance'] > 0 ? '+' : '' }}{{ $this->rupiah($s['variance']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->percent($s['rate']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No invoice lines in this period.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
