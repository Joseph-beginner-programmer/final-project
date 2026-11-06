<?php

use App\Support\Reports\AgingReport;
use App\Support\Reports\ReportPeriod;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Receivables Aging Report')] class extends Component {
    #[Url]
    public string $period = '';

    public function mount(): void
    {
        Gate::authorize('reports.receivables');
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
    public function asOf(): Carbon
    {
        $end = ReportPeriod::end($this->period);

        return $end->lt(today()) ? $end : today();
    }

    #[Computed]
    public function report(): AgingReport
    {
        return AgingReport::receivables($this->asOf);
    }

    #[Computed]
    public function rows(): Collection
    {
        return $this->report->rows();
    }

    #[Computed]
    public function summary(): array
    {
        return $this->report->summary();
    }

    public function rupiah(string|float|null $value): string
    {
        if ($value === null || (float) $value == 0.0) {
            return $value === null ? '—' : 'Rp 0';
        }

        return ((float) $value < 0 ? '−Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.');
    }

    public function bucketLabel(string $key): string
    {
        return __(match ($key) {
            'current' => 'Not yet due',
            '1_30' => '1-30 days',
            '31_60' => '31-60 days',
            '61_90' => '61-90 days',
            default => 'Over 90 days',
        });
    }
}; ?>

@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 whitespace-nowrap')
@php($num = 'py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap')
@php($ink = 'text-zinc-800 dark:text-zinc-200')
@php($exportParams = ['period' => $period])
@php($s = $this->summary)

<section class="w-full max-w-5xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route(auth()->user()->role->reportsRoute())" wire:navigate>{{ __('Reports') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Receivables Aging Report') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Receivables Aging Report') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Piutang Usaha outstanding per customer, as of :date.', ['date' => $this->asOf->translatedFormat('d M Y')]) }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="filled" icon="document-arrow-down" :href="route('accounting.reports.receivables.export', ['format' => 'pdf', ...$exportParams])" class="active:scale-[0.95]">PDF</flux:button>
            <flux:button size="sm" variant="filled" icon="table-cells" :href="route('accounting.reports.receivables.export', ['format' => 'excel', ...$exportParams])" class="active:scale-[0.95]">Excel</flux:button>
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
        <div class="mt-6 grid grid-cols-2 lg:grid-cols-3 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 divide-x divide-y lg:divide-y-0 divide-zinc-100 dark:divide-white/5">
            @foreach ([
                [__('Total outstanding'), $this->rupiah($s['total'])],
                [__('Overdue'), $this->rupiah($s['overdue'])],
                [__('Overdue invoices'), $s['overdue_count']],
            ] as [$label, $value])
                <div class="px-4 py-3.5">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                    <p class="mt-1.5 font-display text-xl font-semibold text-zinc-900 dark:text-white">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('By customer') }}</h2>
        <div class="mt-3 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full min-w-[55rem] text-xs border-collapse">
                <thead>
                    <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="{{ $th }}">{{ __('Customer') }}</th>
                        @foreach (\App\Support\Reports\AgingReport::agingKeys() as $key)
                            <th class="{{ $th }} text-right">{{ $this->bucketLabel($key) }}</th>
                        @endforeach
                        <th class="{{ $th }} text-right">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $row)
                        <tr class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03]">
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ $row['name'] }}</td>
                            @foreach (\App\Support\Reports\AgingReport::agingKeys() as $key)
                                <td class="{{ $num }} {{ $row['buckets'][$key] > 0 && $key !== 'current' ? 'text-red-700 dark:text-red-400 font-medium' : $ink }}">{{ $this->rupiah($row['buckets'][$key]) }}</td>
                            @endforeach
                            <td class="{{ $num }} font-semibold {{ $ink }}">{{ $this->rupiah($row['total']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-12 text-center text-zinc-500 dark:text-zinc-400">{{ __('Nothing outstanding.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
