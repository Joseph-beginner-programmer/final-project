<?php

use App\Support\Reports\PurchaseRegisterReport;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Purchase Register')] class extends Component {
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
    public function report(): PurchaseRegisterReport
    {
        return new PurchaseRegisterReport(ReportPeriod::start($this->period), ReportPeriod::end($this->period));
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
}; ?>

@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 whitespace-nowrap')
@php($num = 'py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap')
@php($ink = 'text-zinc-800 dark:text-zinc-200')
@php($exportParams = ['period' => $period])
@php($t = $this->totals)

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route(auth()->user()->role->reportsRoute())" wire:navigate>{{ __('Reports') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Purchase Register') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Purchase Register') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Every purchase invoice posted in the month.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="filled" icon="document-arrow-down" :href="route('purchasing.reports.register.export', ['format' => 'pdf', ...$exportParams])" class="active:scale-[0.95]">PDF</flux:button>
            <flux:button size="sm" variant="filled" icon="table-cells" :href="route('purchasing.reports.register.export', ['format' => 'excel', ...$exportParams])" class="active:scale-[0.95]">Excel</flux:button>
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
                [__('Invoices'), $this->rows->count(), null],
                [__('Goods'), $this->rupiah($t['goods_amount']), null],
                [__('Tax'), $this->rupiah($t['tax_amount']), null],
                [__('Total'), $this->rupiah($t['total_amount']), __(':paid paid · :balance outstanding', ['paid' => $this->rupiah($t['paid']), 'balance' => $this->rupiah($t['balance'])])],
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

        <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Invoices') }}</h2>
        <div class="mt-3 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full min-w-[65rem] text-xs border-collapse">
                <thead>
                    <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="{{ $th }}">{{ __('Invoice') }}</th>
                        <th class="{{ $th }}">{{ __('PO') }}</th>
                        <th class="{{ $th }}">{{ __('Supplier') }}</th>
                        <th class="{{ $th }}">{{ __('Date') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Goods') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Charges') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Tax') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Total') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Paid') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Balance') }}</th>
                        <th class="{{ $th }}">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $r)
                        <tr wire:key="row-{{ $r['invoice']->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03]">
                            <td class="py-2.5 px-3">
                                <a href="{{ route('accounting.purchase-invoices.show', $r['invoice']) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $r['invoice']->internal_number }}</a>
                                <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $r['invoice']->invoice_number }}</p>
                            </td>
                            <td class="py-2.5 px-3 font-data text-zinc-700 dark:text-zinc-300 whitespace-nowrap">{{ $r['po_number'] }}</td>
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ $r['supplier'] }}</td>
                            <td class="py-2.5 px-3 whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $r['invoice_date']->translatedFormat('d M Y') }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($r['goods_amount']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($r['charges']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($r['tax_amount']) }}</td>
                            <td class="{{ $num }} {{ $ink }} font-semibold">{{ $this->rupiah($r['total_amount']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($r['paid']) }}</td>
                            <td class="{{ $num }} {{ (float) $r['balance'] > 0 ? 'text-amber-700 dark:text-amber-400' : $ink }}">{{ $this->rupiah($r['balance']) }}</td>
                            <td class="py-2.5 px-3"><span class="rounded-full border px-2 py-0.5 text-[11px] {{ $r['status']->badgeClasses() }}">{{ $r['status']->label() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No purchase invoices posted in this period.') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($this->rows->isNotEmpty())
                    <tfoot>
                        <tr class="border-t-2 border-zinc-300 dark:border-white/20 bg-zinc-50 dark:bg-white/5 font-semibold">
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white" colspan="4">{{ __('Total') }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['goods_amount']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['charges']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['tax_amount']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['total_amount']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['paid']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['balance']) }}</td>
                            <td></td>
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
                        <th class="{{ $th }} text-right">{{ __('Invoices') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Goods') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Total') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Paid') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Balance') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->suppliers as $s)
                        <tr class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ $s['supplier'] }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $s['invoices'] }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($s['goods_amount']) }}</td>
                            <td class="{{ $num }} {{ $ink }} font-semibold">{{ $this->rupiah($s['total_amount']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($s['paid']) }}</td>
                            <td class="{{ $num }} {{ (float) $s['balance'] > 0 ? 'text-amber-700 dark:text-amber-400' : $ink }}">{{ $this->rupiah($s['balance']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No purchase invoices posted in this period.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
