<?php

use App\Support\Reports\MarginReport;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Gross Margin Report')] class extends Component {
    #[Url]
    public string $period = '';

    #[Url]
    public string $by = 'product';

    public function mount(): void
    {
        Gate::authorize('reports.margin');
        $this->normalize();
    }

    public function updated(): void
    {
        $this->normalize();
    }

    protected function normalize(): void
    {
        $this->period = ReportPeriod::normalize($this->period);
        $this->by = $this->by === 'customer' ? 'customer' : 'product';
    }

    public function shiftPeriod(int $months): void
    {
        $this->period = ReportPeriod::start($this->period)->addMonthsNoOverflow($months)->format('Y-m');
    }

    #[Computed]
    public function report(): MarginReport
    {
        return new MarginReport(ReportPeriod::start($this->period), ReportPeriod::end($this->period));
    }

    #[Computed]
    public function rows(): Collection
    {
        return $this->by === 'customer' ? $this->report->byCustomer() : $this->report->byProduct();
    }

    #[Computed]
    public function totals(): array
    {
        $revenue = (float) $this->rows->sum('revenue');
        $cost = (float) $this->rows->sum('cost');

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $revenue - $cost,
            'margin' => $revenue > 0 ? ($revenue - $cost) / $revenue * 100 : null,
        ];
    }

    public function rupiah(string|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return ((float) $value < 0 ? '−Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.');
    }

    public function percent(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1, ',', '.').'%';
    }
}; ?>

@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 whitespace-nowrap')
@php($num = 'py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap')
@php($ink = 'text-zinc-800 dark:text-zinc-200')
@php($exportParams = ['period' => $period, 'by' => $by])
@php($t = $this->totals)

<section class="w-full max-w-5xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route(auth()->user()->role->reportsRoute())" wire:navigate>{{ __('Reports') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Gross Margin Report') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Gross Margin Report') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Revenue vs. cost of goods sold on posted deliveries, by product or by customer.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="filled" icon="document-arrow-down" :href="route('accounting.reports.margin.export', ['format' => 'pdf', ...$exportParams])" class="active:scale-[0.95]">PDF</flux:button>
            <flux:button size="sm" variant="filled" icon="table-cells" :href="route('accounting.reports.margin.export', ['format' => 'excel', ...$exportParams])" class="active:scale-[0.95]">Excel</flux:button>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400 me-1">{{ __('Period') }}</span>
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="shiftPeriod(-1)" :aria-label="__('Previous month')" class="active:scale-[0.95]" />
            <div class="w-44">
                <flux:input size="sm" type="month" wire:model.live="period" :aria-label="__('Period')" />
            </div>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="shiftPeriod(1)" :aria-label="__('Next month')" class="active:scale-[0.95]" />
        </div>
        <div class="flex items-center gap-1.5 rounded-lg bg-zinc-100 dark:bg-white/5 p-1">
            <button type="button" wire:click="$set('by', 'product')" class="rounded-md px-3 py-1 text-xs font-medium transition-colors {{ $by === 'product' ? 'bg-white dark:bg-zinc-800 shadow-sm text-zinc-900 dark:text-white' : 'text-zinc-500 dark:text-zinc-400' }}">{{ __('Per product') }}</button>
            <button type="button" wire:click="$set('by', 'customer')" class="rounded-md px-3 py-1 text-xs font-medium transition-colors {{ $by === 'customer' ? 'bg-white dark:bg-zinc-800 shadow-sm text-zinc-900 dark:text-white' : 'text-zinc-500 dark:text-zinc-400' }}">{{ __('Per customer') }}</button>
        </div>
    </div>

    <div wire:loading.class="opacity-50" wire:target="period,shiftPeriod,by" class="transition-opacity">
        <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 divide-x divide-y lg:divide-y-0 divide-zinc-100 dark:divide-white/5">
            @foreach ([
                [__('Revenue'), $this->rupiah($t['revenue']), null],
                [__('Cost of goods sold'), $this->rupiah($t['cost']), null],
                [__('Gross profit'), $this->rupiah($t['profit']), null],
                [__('Margin'), $this->percent($t['margin']), null],
            ] as [$label, $value, $note])
                <div class="px-4 py-3.5">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                    <p class="mt-1.5 font-display text-xl font-semibold text-zinc-900 dark:text-white">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ $by === 'customer' ? __('By customer') : __('By product') }}</h2>
        <div class="mt-3 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full min-w-[45rem] text-xs border-collapse">
                <thead>
                    <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="{{ $th }}">{{ $by === 'customer' ? __('Customer') : __('Product') }}</th>
                        @if ($by === 'product')
                            <th class="{{ $th }} text-right">{{ __('Quantity') }}</th>
                        @endif
                        <th class="{{ $th }} text-right">{{ __('Revenue') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Cost of goods sold') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Gross profit') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Margin') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $row)
                        <tr class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03]">
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ $row['name'] }}</td>
                            @if ($by === 'product')
                                <td class="{{ $num }} {{ $ink }}">{{ number_format($row['quantity'], 0, ',', '.') }}</td>
                            @endif
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($row['revenue']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($row['cost']) }}</td>
                            <td class="{{ $num }} font-semibold {{ $row['profit'] < 0 ? 'text-red-700 dark:text-red-400' : $ink }}">{{ $this->rupiah($row['profit']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->percent($row['margin']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $by === 'product' ? 6 : 5 }}" class="py-12 text-center text-zinc-500 dark:text-zinc-400">{{ __('No deliveries in this month.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
