<?php

use App\Enums\ProductType;
use App\Support\Reports\ProductionCostReport;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Production Cost Report')] class extends Component {
    #[Url]
    public string $period = '';

    #[Url]
    public string $type = '';

    public function mount(): void
    {
        Gate::authorize('reports.production');
        $this->normalize();
    }

    public function updated(): void
    {
        $this->normalize();
    }

    protected function normalize(): void
    {
        $this->period = ReportPeriod::normalize($this->period);
        $this->type = ReportPeriod::type($this->type, [ProductType::FinishedGoods, ProductType::Wip])?->value ?? '';
    }

    public function shiftPeriod(int $months): void
    {
        $this->period = ReportPeriod::start($this->period)->addMonthsNoOverflow($months)->format('Y-m');
    }

    #[Computed]
    public function report(): ProductionCostReport
    {
        return new ProductionCostReport(ReportPeriod::start($this->period), ReportPeriod::end($this->period), ProductType::tryFrom($this->type));
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
    public function products(): Collection
    {
        return $this->report->productSummary($this->rows);
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
        return number_format((float) $value, 0, ',', '.');
    }

    public function percent(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1, ',', '.').'%';
    }
}; ?>

@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 whitespace-nowrap')
@php($num = 'py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap')
@php($ink = 'text-zinc-800 dark:text-zinc-200')
@php($muted = 'py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-500 dark:text-zinc-400')
@php($exportParams = ['period' => $period, 'type' => $type ?: null])
@php($t = $this->totals)

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('production.reports.index')" wire:navigate>{{ __('Reports') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Production Cost Report') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Production Cost Report') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Cost of every work order whose production result was posted in the month.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="filled" icon="document-arrow-down" :href="route('production.reports.cost.export', ['format' => 'pdf', ...$exportParams])" class="active:scale-[0.95]">PDF</flux:button>
            <flux:button size="sm" variant="filled" icon="table-cells" :href="route('production.reports.cost.export', ['format' => 'csv', ...$exportParams])" class="active:scale-[0.95]">CSV</flux:button>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400 me-1">{{ __('Period') }}</span>
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="shiftPeriod(-1)" :aria-label="__('Previous month')" class="active:scale-[0.95]" />
            <div class="w-44">
                <flux:input size="sm" type="month" wire:model.live="period" :aria-label="__('Period')" />
            </div>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="shiftPeriod(1)" :aria-label="__('Next month')" class="active:scale-[0.95]" />
        </div>
        <div class="w-80">
            <flux:radio.group wire:model.live="type" variant="segmented" size="sm" :aria-label="__('Product type')">
                <flux:radio value="" :label="__('All')" />
                <flux:radio value="finished_goods" :label="__('Finished goods')" />
                <flux:radio value="wip" :label="__('WIP')" />
            </flux:radio.group>
        </div>
    </div>

    <div wire:loading.class="opacity-50" wire:target="period,type,shiftPeriod" class="transition-opacity">
        <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ([
                [__('Total production cost'), $this->rupiah($t['total']), __(':count work orders', ['count' => $this->rows->count()])],
                [__('Material'), $this->rupiah($t['material']), __(':share of total', ['share' => $this->percent(ProductionCostReport::share($t['material'], $t['total']))])],
                [__('Labor'), $this->rupiah($t['labor']), __(':share of total', ['share' => $this->percent(ProductionCostReport::share($t['labor'], $t['total']))])],
                [__('Overhead'), $this->rupiah($t['overhead']), __(':share of total', ['share' => $this->percent(ProductionCostReport::share($t['overhead'], $t['total']))])],
            ] as [$label, $value, $note])
                <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-4">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                    <p class="mt-2 text-xl font-semibold text-zinc-900 dark:text-white">{{ $value }}</p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $note }}</p>
                </div>
            @endforeach
        </div>

        <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Per work order') }}</h2>
        <div class="mt-3 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full min-w-[60rem] text-xs border-collapse">
                <thead>
                    <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="{{ $th }}">{{ __('Work order') }}</th>
                        <th class="{{ $th }}">{{ __('Completed on') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Good units') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Material') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Labor') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Overhead') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Total cost') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Unit cost') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Labor + overhead vs plan') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $r)
                        @php($over = (float) $r['difference'] > 0)
                        <tr wire:key="cost-{{ $r['result']->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03]">
                            <td class="py-2.5 px-3">
                                <a href="{{ route('production.results.show', $r['result']) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $r['wo_number'] }}</a>
                                <p class="text-[11px] text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ $r['product'] }}</p>
                            </td>
                            <td class="py-2.5 px-3 whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $r['completed_on']->translatedFormat('d M Y') }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->number($r['good']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($r['material']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($r['labor']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($r['overhead']) }}</td>
                            <td class="{{ $num }} {{ $ink }} font-semibold">{{ $this->rupiah($r['total']) }}</td>
                            <td class="{{ $num }} {{ $ink }} font-semibold">{{ $this->rupiah($r['unit_cost']) }}</td>
                            <td class="{{ $num }} {{ $over ? 'text-red-700 dark:text-red-400' : ((float) $r['difference'] < 0 ? 'text-green-700 dark:text-green-400' : 'text-zinc-500 dark:text-zinc-400') }}">{{ $over ? '+' : '' }}{{ $this->rupiah($r['difference']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No work orders completed in this period.') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($this->rows->isNotEmpty())
                    <tfoot>
                        <tr class="border-t-2 border-zinc-300 dark:border-white/20 bg-zinc-50 dark:bg-white/5 font-semibold">
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white" colspan="2">{{ __('Total') }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->number($t['good']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['material']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['labor']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['overhead']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['total']) }}</td>
                            <td></td>
                            <td class="{{ $num }} {{ $ink }}">{{ (float) $t['difference'] > 0 ? '+' : '' }}{{ $this->rupiah($t['difference']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Summary per product') }}</h2>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Average unit cost = total cost ÷ good units across all work orders of that product in the month.') }}</p>
        <div class="mt-3 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full min-w-[60rem] text-xs border-collapse">
                <thead>
                    <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="{{ $th }}">{{ __('Product') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Work orders') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Good units') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Material') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Labor') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Overhead') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Total cost') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Average unit cost') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Lowest – highest') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->products as $p)
                        <tr class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ $p['product'] }}</td>
                            <td class="{{ $muted }}">{{ $p['work_orders'] }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->number($p['good']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($p['material']) }} <span class="text-[10px] text-zinc-400">{{ $this->percent(ProductionCostReport::share($p['material'], $p['total'])) }}</span></td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($p['labor']) }} <span class="text-[10px] text-zinc-400">{{ $this->percent(ProductionCostReport::share($p['labor'], $p['total'])) }}</span></td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($p['overhead']) }} <span class="text-[10px] text-zinc-400">{{ $this->percent(ProductionCostReport::share($p['overhead'], $p['total'])) }}</span></td>
                            <td class="{{ $num }} {{ $ink }} font-semibold">{{ $this->rupiah($p['total']) }}</td>
                            <td class="{{ $num }} {{ $ink }} font-semibold">{{ $this->rupiah($p['average_unit_cost']) }}</td>
                            <td class="{{ $muted }}">{{ $p['work_orders'] > 1 ? $this->rupiah($p['lowest_unit_cost']).' – '.$this->rupiah($p['highest_unit_cost']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No work orders completed in this period.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Unit cost = total cost ÷ good units (rejects are absorbed by the good units). Labor + overhead vs plan = actual − planned; material has no planned cost.') }}</p>
    </div>
</section>
