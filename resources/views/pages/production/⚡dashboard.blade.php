<?php

use App\Enums\ProductType;
use App\Support\Kpi\ProductionKpis;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Production Dashboard')] class extends Component {
    #[Url]
    public string $period = '';

    #[Url]
    public string $type = 'finished_goods';

    public function mount(): void
    {
        $this->normalize();
    }

    public function updated(): void
    {
        $this->normalize();
    }

    protected function normalize(): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->period) !== 1) {
            $this->period = now()->format('Y-m');
        }

        if (! in_array($this->type, [ProductType::FinishedGoods->value, ProductType::Wip->value], true)) {
            $this->type = ProductType::FinishedGoods->value;
        }
    }

    public function shiftPeriod(int $months): void
    {
        $this->period = $this->periodStart()->addMonthsNoOverflow($months)->format('Y-m');
    }

    protected function periodStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->period.'-01')->startOfDay();
    }

    #[Computed]
    public function kpis(): ProductionKpis
    {
        $start = $this->periodStart();

        return new ProductionKpis($start, $start->copy()->endOfMonth(), ProductType::from($this->type));
    }

    #[Computed]
    public function periodLabel(): string
    {
        return $this->periodStart()->translatedFormat('F Y');
    }

    #[Computed]
    public function completed(): Collection
    {
        return $this->kpis->completedWorkOrders();
    }

    #[Computed]
    public function output(): array
    {
        return $this->kpis->output();
    }

    #[Computed]
    public function losses(): array
    {
        return $this->kpis->losses();
    }

    #[Computed]
    public function rejectByProduct(): Collection
    {
        return $this->kpis->rejectRateByProduct();
    }

    #[Computed]
    public function attention(): array
    {
        return ProductionKpis::attention(now());
    }

    #[Computed]
    public function seriesProducts(): Collection
    {
        return $this->kpis->seriesProducts()->values()->map(fn ($p, $i) => [...$p, 'slot' => $i + 1]);
    }

    #[Computed]
    public function weeklyOutput(): array
    {
        $data = $this->kpis->weeklyOutput($this->seriesProducts);
        $slots = $this->seriesProducts->pluck('slot', 'id');

        return [
            'labels' => $data['weeks'],
            'series' => collect($data['series'])->map(fn ($s) => [...$s, 'slot' => $slots[$s['id']] ?? ProductionKpis::MAX_PRODUCT_SERIES + 1])->all(),
        ];
    }

    #[Computed]
    public function rejectTrend(): array
    {
        $data = $this->kpis->rejectTrend($this->seriesProducts);
        $slots = $this->seriesProducts->pluck('slot', 'id');

        return [
            'labels' => $data['months'],
            'overall' => $data['overall'],
            'series' => collect($data['series'])->map(fn ($s) => [...$s, 'slot' => $slots[$s['id']]])->all(),
        ];
    }

    #[Computed]
    public function lossesTrend(): array
    {
        return $this->kpis->lossesTrend();
    }

    #[Computed]
    public function utilisation(): Collection
    {
        return $this->kpis->workCenterUtilisation();
    }

    #[Computed]
    public function planVsActual(): Collection
    {
        return $this->completed->sortByDesc(fn ($row) => abs((float) $row['cost_difference']))->take(10)->values();
    }

    #[Computed]
    public function onTime(): array
    {
        $total = $this->completed->count();
        $onTime = $this->completed->where('on_time', true)->count();

        return ['total' => $total, 'on_time' => $onTime, 'late' => $total - $onTime, 'rate' => $total > 0 ? $onTime / $total * 100 : null];
    }

    public function formatRupiah(string|float $amount): string
    {
        $prefix = (float) $amount < 0 ? '−Rp ' : 'Rp ';

        return $prefix.number_format(abs((float) $amount), 0, ',', '.');
    }

    public function formatQuantity(string|float $quantity): string
    {
        return number_format((float) $quantity, 0, ',', '.');
    }

    public function formatPercent(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1, ',', '.').'%';
    }
}; ?>

<style>
    @keyframes fade-slide-up {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .motion-safe\:animate-fade-slide-up {
        animation: fade-slide-up .5s cubic-bezier(.16,1,.3,1) backwards;
    }
    @media (prefers-reduced-motion: reduce) {
        .motion-safe\:animate-fade-slide-up { animation: none; }
    }
</style>

@php($card = 'rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-4 sm:p-5 shadow-sm shadow-zinc-900/5 dark:shadow-none')
@php($cardTitle = 'text-sm font-semibold text-zinc-900 dark:text-white')
@php($cardSub = 'mt-0.5 text-xs text-zinc-500 dark:text-zinc-400')
@php($tileLabel = 'text-xs font-medium text-zinc-500 dark:text-zinc-400')
@php($tileValue = 'mt-2 text-2xl font-semibold text-zinc-900 dark:text-white')
@php($tileNote = 'mt-1 text-xs text-zinc-500 dark:text-zinc-400')
@php($th = 'py-1.5 px-2 text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')
@php($td = 'py-1.5 px-2 text-zinc-700 dark:text-zinc-300')
@php($tdNum = 'py-1.5 px-2 text-right tabular-nums text-zinc-800 dark:text-zinc-200 whitespace-nowrap')
@php($tableToggle = 'shrink-0 text-xs font-medium text-accent hover:underline underline-offset-2 cursor-pointer')
@php($key = $period.'-'.$type)

<section class="viz-root w-full max-w-6xl mx-auto">
    <div class="flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Production Dashboard') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-3 motion-safe:animate-fade-slide-up" style="animation-delay: 20ms;">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400 me-1">{{ __('Period') }}</span>
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="shiftPeriod(-1)" :aria-label="__('Previous month')" class="active:scale-[0.95]" />
            <div class="w-44">
                <flux:input size="sm" type="month" wire:model.live="period" :aria-label="__('Period')" />
            </div>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="shiftPeriod(1)" :aria-label="__('Next month')" class="active:scale-[0.95]" />
            @if ($period !== now()->format('Y-m'))
                <flux:button size="sm" variant="ghost" wire:click="$set('period', '{{ now()->format('Y-m') }}')" class="active:scale-[0.95]">{{ __('This month') }}</flux:button>
            @endif
        </div>
        <div class="w-64">
            <flux:radio.group wire:model.live="type" variant="segmented" size="sm" :aria-label="__('Product type')">
                <flux:radio value="finished_goods" :label="__('Finished goods')" />
                <flux:radio value="wip" :label="__('WIP')" />
            </flux:radio.group>
        </div>
    </div>

    <div wire:loading.class="opacity-50" wire:target="period,type,shiftPeriod" class="transition-opacity">
        <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
            <div class="{{ $card }}">
                <p class="{{ $tileLabel }}">{{ __('Work orders completed') }}</p>
                <p class="{{ $tileValue }}">{{ $this->completed->count() }}</p>
                <p class="{{ $tileNote }}">{{ __(':qty good units', ['qty' => $this->formatQuantity($this->output['good'])]) }}</p>
            </div>
            <div class="{{ $card }}">
                <p class="{{ $tileLabel }}">{{ __('Reject rate') }}</p>
                <p class="{{ $tileValue }}">{{ $this->formatPercent($this->output['reject_rate']) }}</p>
                <p class="{{ $tileNote }}">{{ __(':qty rejected units', ['qty' => $this->formatQuantity($this->output['reject'])]) }}</p>
            </div>
            <div class="{{ $card }}">
                <p class="{{ $tileLabel }}">{{ __('On-time completion') }}</p>
                <p class="{{ $tileValue }}">{{ $this->formatPercent($this->onTime['rate']) }}</p>
                @if ($this->onTime['total'] > 0)
                    <div class="mt-2 flex h-1.5 gap-0.5 overflow-hidden rounded-full" role="img" aria-label="{{ __(':on of :total on time', ['on' => $this->onTime['on_time'], 'total' => $this->onTime['total']]) }}">
                        @if ($this->onTime['on_time'] > 0)
                            <div class="h-full rounded-s-full" style="width: {{ $this->onTime['rate'] }}%; background: var(--status-good)"></div>
                        @endif
                        @if ($this->onTime['late'] > 0)
                            <div class="h-full flex-1 rounded-e-full" style="background: var(--status-warning)"></div>
                        @endif
                    </div>
                    <p class="{{ $tileNote }}">{{ __(':on on time · :late late', ['on' => $this->onTime['on_time'], 'late' => $this->onTime['late']]) }}</p>
                @else
                    <p class="{{ $tileNote }}">{{ __('No work orders completed in this period.') }}</p>
                @endif
            </div>
            <div class="{{ $card }}">
                <p class="{{ $tileLabel }}">{{ __('Losses') }}</p>
                <p class="{{ $tileValue }}">{{ $this->formatRupiah($this->losses['total']) }}</p>
                <p class="{{ $tileNote }}">{{ __('Material :material · Product :product', ['material' => $this->formatRupiah($this->losses['material_variance']), 'product' => $this->formatRupiah($this->losses['production_loss'])]) }}</p>
            </div>
        </div>

        @php($attention = $this->attention)
        @php($attentionItems = array_filter([
            ['count' => $attention['overdue']->count(), 'label' => __('work orders past their planned end date'), 'href' => route('production.work-orders.list'), 'tone' => 'red'],
            ['count' => $attention['awaiting_result'], 'label' => __('work orders in progress without a production result'), 'href' => route('production.results.list'), 'tone' => 'amber'],
            ['count' => $attention['draft_results'], 'label' => __('production results still in draft'), 'href' => route('production.results.list'), 'tone' => 'amber'],
            ['count' => $attention['awaiting_receipt'], 'label' => __('results waiting for the warehouse to receive the goods'), 'href' => null, 'tone' => 'zinc'],
            ['count' => $attention['awaiting_return'], 'label' => __('results with leftover material not yet returned'), 'href' => null, 'tone' => 'zinc'],
        ], fn ($item) => $item['count'] > 0))

        <div class="mt-6 motion-safe:animate-fade-slide-up" style="animation-delay: 60ms;">
            <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Needs attention') }}</h2>
            <p class="{{ $cardSub }}">{{ __('Right now, across all products.') }}</p>
            <div class="mt-3 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 divide-y divide-zinc-100 dark:divide-white/5">
                @forelse ($attentionItems as $item)
                    @php($dot = ['red' => 'bg-red-500', 'amber' => 'bg-amber-500', 'zinc' => 'bg-zinc-400'][$item['tone']])
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                        <span class="flex items-center gap-2.5 text-zinc-700 dark:text-zinc-300">
                            <span class="size-2 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>
                            <span><span class="font-semibold text-zinc-900 dark:text-white">{{ $item['count'] }}</span> {{ $item['label'] }}</span>
                        </span>
                        @if ($item['href'])
                            <a href="{{ $item['href'] }}" wire:navigate class="shrink-0 text-xs font-medium text-accent hover:underline underline-offset-2">{{ __('Open') }}</a>
                        @endif
                    </div>
                    @if ($item['tone'] === 'red')
                        <div class="px-4 pb-3 -mt-1 flex flex-wrap gap-1.5">
                            @foreach ($attention['overdue']->take(6) as $wo)
                                <a href="{{ route('production.work-orders.show', $wo) }}" wire:navigate class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 text-[11px] text-red-700 dark:text-red-400 hover:underline">
                                    <span class="font-data">{{ $wo->wo_number }}</span> · {{ $wo->planned_end_date->format('d/m') }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                @empty
                    <div class="flex items-center gap-2 px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">
                        <flux:icon.check-circle variant="micro" class="size-4 text-green-600 dark:text-green-400" />{{ __('Nothing needs attention right now.') }}
                    </div>
                @endforelse
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6 motion-safe:animate-fade-slide-up" style="animation-delay: 80ms;">
            @php($weekly = $this->weeklyOutput)
            <div wire:key="output-{{ $key }}" class="{{ $card }} lg:col-span-2 min-w-0" x-data="{ table: false }">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="{{ $cardTitle }}">{{ __('Good units per week') }}</h2>
                        <p class="{{ $cardSub }}">{{ __('Click a product to isolate it and see its target.') }}</p>
                    </div>
                    <button type="button" class="{{ $tableToggle }}" x-on:click="table = ! table" x-text="table ? @js(__('Show chart')) : @js(__('Show table'))"></button>
                </div>
                @if (collect($weekly['series'])->isEmpty())
                    <p class="py-16 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No production in this period.') }}</p>
                @else
                    <div wire:ignore x-data="stackedBar(@js([...$weekly, 'isolatable' => true, 'targets' => true, 'format' => 'number', 'totalLabel' => __('Total'), 'targetLabel' => __('Target')]))">
                        <div x-show="! table" class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
                            <template x-for="s in config.series" :key="s.id">
                                <button type="button" x-on:click="toggle(s.id)" :aria-pressed="isolated === s.id" class="inline-flex items-center gap-1.5 text-xs cursor-pointer transition-opacity" :class="isDimmed(s.id) ? 'opacity-40' : ''">
                                    <span class="size-2.5 rounded-sm" :style="`background: var(--series-${s.slot})`"></span>
                                    <span class="text-zinc-700 dark:text-zinc-300" x-text="s.name"></span>
                                </button>
                            </template>
                            <span x-show="isolated !== null" class="inline-flex items-center gap-1.5 text-xs text-zinc-700 dark:text-zinc-300">
                                <span class="h-0.5 w-3.5 rounded-full" style="background: var(--viz-ink)"></span>{{ __('Target') }}
                            </span>
                        </div>
                        <div x-show="! table" x-ref="frame" class="relative mt-2 w-full">
                            <svg x-ref="svg" class="block" height="240" role="group" aria-label="{{ __('Good units per week') }}"></svg>
                            <x-chart-tooltip />
                        </div>
                    </div>
                    <div x-show="table" x-cloak class="mt-3 overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead><tr class="border-b border-zinc-200 dark:border-white/10"><th class="{{ $th }}">{{ __('Product') }}</th>@foreach ($weekly['labels'] as $label)<th class="{{ $th }} text-right">{{ $label }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach ($weekly['series'] as $s)
                                    <tr class="border-b border-zinc-100 dark:border-white/5"><td class="{{ $td }}">{{ $s['name'] }}</td>@foreach ($s['values'] as $i => $v)<td class="{{ $tdNum }}">{{ $this->formatQuantity($v) }} <span class="text-zinc-400">/ {{ $this->formatQuantity($s['targets'][$i]) }}</span></td>@endforeach</tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="mt-2 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Good units / target (target counted in the week of the planned end date).') }}</p>
                    </div>
                @endif
            </div>

            <div class="{{ $card }} min-w-0">
                <h2 class="{{ $cardTitle }}">{{ __('Work center utilisation') }}</h2>
                <p class="{{ $cardSub }}">{{ __('Machine hours used vs the monthly budget in the overhead rate.') }}</p>
                <div class="mt-4 space-y-4">
                    @forelse ($this->utilisation as $center)
                        <div>
                            <div class="flex items-baseline justify-between gap-3 text-xs">
                                <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $center['name'] }}</span>
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->formatPercent($center['utilisation']) }}</span>
                            </div>
                            @if ($center['budget'])
                                <div class="mt-1.5 h-2 rounded-full overflow-hidden" style="background: var(--meter-track)" role="img" aria-label="{{ $center['name'] }} {{ $this->formatPercent($center['utilisation']) }}">
                                    <div class="h-full rounded-full" style="width: {{ min(100, $center['utilisation']) }}%; background: {{ $center['utilisation'] > 100 ? 'var(--status-warning)' : 'var(--meter-fill)' }}"></div>
                                </div>
                                <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">
                                    {{ __(':actual of :budget hours', ['actual' => $this->formatQuantity($center['actual']), 'budget' => $this->formatQuantity($center['budget'])]) }}
                                    @if ($center['utilisation'] > 100)
                                        · <span class="inline-flex items-center gap-0.5 font-medium text-amber-700 dark:text-amber-400"><flux:icon.exclamation-triangle variant="micro" class="size-3" />{{ __('over budget') }}</span>
                                    @endif
                                </p>
                            @else
                                <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __(':actual hours · no budgeted hours set', ['actual' => $this->formatQuantity($center['actual'])]) }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="py-4 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No work centers yet.') }}</p>
                    @endforelse
                </div>
                <p class="mt-4 border-t border-zinc-100 dark:border-white/5 pt-3 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Below 100% means machines ran less than budgeted, so the real overhead per hour is higher than the rate.') }}</p>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6 motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
            @php($rows = $this->planVsActual)
            @php($maxAbs = max(1, $rows->max(fn ($r) => abs((float) $r['cost_difference'])) ?? 1))
            @php($overCount = $this->completed->where('over_plan', true)->count())
            <div class="{{ $card }} lg:col-span-2 min-w-0" x-data="{ table: false }">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="{{ $cardTitle }}">{{ __('Plan vs actual cost per work order') }}</h2>
                        <p class="{{ $cardSub }}">{{ __('Labor + overhead. :over of :total work orders over plan (cost or material).', ['over' => $overCount, 'total' => $this->completed->count()]) }}</p>
                    </div>
                    <button type="button" class="{{ $tableToggle }}" x-on:click="table = ! table" x-text="table ? @js(__('Show chart')) : @js(__('Show table'))"></button>
                </div>
                @if ($rows->isEmpty())
                    <p class="py-16 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No work orders completed in this period.') }}</p>
                @else
                    <div x-show="! table">
                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-zinc-700 dark:text-zinc-300">
                            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background: var(--div-under)"></span>{{ __('Under plan') }}</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background: var(--div-over)"></span>{{ __('Over plan') }}</span>
                            <span class="inline-flex items-center gap-1.5"><flux:icon.cube variant="micro" class="size-3.5 text-zinc-500" />{{ __('Material used over plan') }}</span>
                        </div>
                        <div class="mt-3 space-y-1">
                            @foreach ($rows as $row)
                                @php($diff = (float) $row['cost_difference'])
                                @php($width = abs($diff) / $maxAbs * 50)
                                <div wire:key="pva-{{ $key }}-{{ $row['result']->id }}" x-data="{ hover: false }" x-on:mouseenter="hover = true" x-on:mouseleave="hover = false" x-on:focusin="hover = true" x-on:focusout="hover = false" class="relative grid grid-cols-[7.5rem_minmax(0,1fr)] sm:grid-cols-[9rem_minmax(0,1fr)] items-center gap-3 rounded-md px-1 py-1 hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                    <div class="min-w-0">
                                        <a href="{{ route('production.results.show', $row['result']) }}" wire:navigate class="flex items-center gap-1 font-data text-xs text-accent hover:underline underline-offset-2">
                                            {{ $row['result']->workOrder->wo_number }}
                                            @if ($row['materials_over_plan'] > 0)
                                                <flux:icon.cube variant="micro" class="size-3.5 text-zinc-500" :aria-label="__(':count over plan', ['count' => $row['materials_over_plan']])" />
                                            @endif
                                        </a>
                                        <p class="truncate text-[11px] text-zinc-500 dark:text-zinc-400">{{ $row['result']->workOrder->product->product_name }}</p>
                                    </div>
                                    <div class="relative h-6">
                                        <div class="absolute inset-y-0 left-1/2 w-px" style="background: var(--div-mid)"></div>
                                        @if ($diff > 0)
                                            <div class="absolute top-1/2 -translate-y-1/2 left-1/2 h-3.5 rounded-e" style="width: {{ $width }}%; background: var(--div-over)"></div>
                                            <span class="absolute top-1/2 -translate-y-1/2 right-1/2 pe-1.5 text-[11px] font-medium text-zinc-800 dark:text-zinc-200 whitespace-nowrap">+{{ $this->formatRupiah($diff) }}</span>
                                        @elseif ($diff < 0)
                                            <div class="absolute top-1/2 -translate-y-1/2 right-1/2 h-3.5 rounded-s" style="width: {{ $width }}%; background: var(--div-under)"></div>
                                            <span class="absolute top-1/2 -translate-y-1/2 left-1/2 ps-1.5 text-[11px] font-medium text-zinc-800 dark:text-zinc-200 whitespace-nowrap">{{ $this->formatRupiah($diff) }}</span>
                                        @else
                                            <span class="absolute top-1/2 -translate-y-1/2 text-[11px] text-zinc-500 dark:text-zinc-400 ps-1.5" style="left: 50%">{{ __('On plan') }}</span>
                                        @endif
                                    </div>
                                    <div x-show="hover" x-cloak class="pointer-events-none absolute right-2 -top-14 z-10 rounded-lg border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-800 px-3 py-2 text-xs shadow-lg">
                                        <p class="font-medium text-zinc-900 dark:text-white">{{ $row['result']->workOrder->wo_number }}</p>
                                        <p class="text-zinc-500 dark:text-zinc-400"><span class="font-semibold text-zinc-900 dark:text-white">{{ $this->formatRupiah($row['planned_cost']) }}</span> {{ __('planned') }} · <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->formatRupiah($row['actual_cost']) }}</span> {{ __('actual') }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @if ($this->completed->count() > 10)
                            <p class="mt-2 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Showing the 10 largest differences of :total.', ['total' => $this->completed->count()]) }}</p>
                        @endif
                    </div>
                    <div x-show="table" x-cloak class="mt-3 overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead><tr class="border-b border-zinc-200 dark:border-white/10"><th class="{{ $th }}">{{ __('Work order') }}</th><th class="{{ $th }} text-right">{{ __('Planned') }}</th><th class="{{ $th }} text-right">{{ __('Actual') }}</th><th class="{{ $th }} text-right">{{ __('Difference') }}</th><th class="{{ $th }}">{{ __('Material') }}</th></tr></thead>
                            <tbody>
                                @foreach ($this->completed as $row)
                                    <tr class="border-b border-zinc-100 dark:border-white/5">
                                        <td class="{{ $td }}">{{ $row['result']->workOrder->wo_number }} · {{ $row['result']->workOrder->product->product_name }}</td>
                                        <td class="{{ $tdNum }}">{{ $this->formatRupiah($row['planned_cost']) }}</td>
                                        <td class="{{ $tdNum }}">{{ $this->formatRupiah($row['actual_cost']) }}</td>
                                        <td class="{{ $tdNum }}">{{ (float) $row['cost_difference'] > 0 ? '+' : '' }}{{ $this->formatRupiah($row['cost_difference']) }}</td>
                                        <td class="{{ $td }}">{{ $row['materials_over_plan'] > 0 ? __(':count over plan', ['count' => $row['materials_over_plan']]) : __('Within plan') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="{{ $card }} min-w-0">
                <h2 class="{{ $cardTitle }}">{{ __('Reject rate by product') }}</h2>
                <p class="{{ $cardSub }}">{{ __('Highest first, this period.') }}</p>
                <div class="mt-4 space-y-3.5">
                    @forelse ($this->rejectByProduct as $row)
                        <div>
                            <div class="flex items-baseline justify-between gap-3 text-xs">
                                <span class="truncate text-zinc-700 dark:text-zinc-300">{{ $row['product'] }}</span>
                                <span class="shrink-0 font-semibold text-zinc-900 dark:text-white">{{ $this->formatPercent($row['reject_rate']) }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 rounded-full overflow-hidden" style="background: var(--meter-track)" role="img" aria-label="{{ $row['product'] }} {{ $this->formatPercent($row['reject_rate']) }}">
                                <div class="h-full rounded-full" style="width: {{ min(100, $row['reject_rate']) }}%; background: var(--meter-fill)"></div>
                            </div>
                            <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __(':reject of :total units', ['reject' => $this->formatQuantity($row['reject']), 'total' => $this->formatQuantity($row['good'] + $row['reject'])]) }}</p>
                        </div>
                    @empty
                        <p class="py-4 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No production in this period.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6 motion-safe:animate-fade-slide-up" style="animation-delay: 120ms;">
            @php($trend = $this->rejectTrend)
            <div wire:key="reject-{{ $key }}" class="{{ $card }} min-w-0" x-data="{ table: false }">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="{{ $cardTitle }}">{{ __('Reject rate, last 6 months') }}</h2>
                        <p class="{{ $cardSub }}">{{ __('All products; click a product to compare.') }}</p>
                    </div>
                    <button type="button" class="{{ $tableToggle }}" x-on:click="table = ! table" x-text="table ? @js(__('Show chart')) : @js(__('Show table'))"></button>
                </div>
                <div wire:ignore x-data="lineTrend(@js([...$trend, 'isolatable' => true, 'format' => 'percent', 'axisFormat' => 'percentAxis', 'overallLabel' => __('All products'), 'ariaLabel' => __('Reject rate, last 6 months')]))">
                    <div x-show="! table" class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
                        <span class="inline-flex items-center gap-1.5 text-xs text-zinc-700 dark:text-zinc-300"><span class="h-0.5 w-3.5 rounded-full" style="background: var(--viz-ink-secondary)"></span>{{ __('All products') }}</span>
                        <template x-for="s in config.series" :key="s.id">
                            <button type="button" x-on:click="toggle(s.id)" :aria-pressed="isolated === s.id" class="inline-flex items-center gap-1.5 text-xs cursor-pointer transition-opacity" :class="isolated === s.id ? '' : 'opacity-50'">
                                <span class="h-0.5 w-3.5 rounded-full" :style="`background: var(--series-${s.slot})`"></span>
                                <span class="text-zinc-700 dark:text-zinc-300" x-text="s.name"></span>
                            </button>
                        </template>
                    </div>
                    <div x-show="! table" x-ref="frame" class="relative mt-2 w-full">
                        <svg x-ref="svg" class="block" height="220" role="group" aria-label="{{ __('Reject rate, last 6 months') }}"></svg>
                        <x-chart-tooltip />
                    </div>
                </div>
                <div x-show="table" x-cloak class="mt-3 overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead><tr class="border-b border-zinc-200 dark:border-white/10"><th class="{{ $th }}">{{ __('Product') }}</th>@foreach ($trend['labels'] as $label)<th class="{{ $th }} text-right">{{ $label }}</th>@endforeach</tr></thead>
                        <tbody>
                            <tr class="border-b border-zinc-100 dark:border-white/5"><td class="{{ $td }} font-medium">{{ __('All products') }}</td>@foreach ($trend['overall'] as $v)<td class="{{ $tdNum }}">{{ $this->formatPercent($v) }}</td>@endforeach</tr>
                            @foreach ($trend['series'] as $s)
                                <tr class="border-b border-zinc-100 dark:border-white/5"><td class="{{ $td }}">{{ $s['name'] }}</td>@foreach ($s['values'] as $v)<td class="{{ $tdNum }}">{{ $this->formatPercent($v) }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @php($lossTrend = $this->lossesTrend)
            @php($lossSeries = [
                ['id' => 'material', 'name' => __('Material variance'), 'values' => $lossTrend['material_variance'], 'slot' => 1],
                ['id' => 'product', 'name' => __('Production loss'), 'values' => $lossTrend['production_loss'], 'slot' => 2],
            ])
            <div wire:key="losses-{{ $key }}" class="{{ $card }} min-w-0" x-data="{ table: false }">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="{{ $cardTitle }}">{{ __('Losses, last 6 months') }}</h2>
                        <p class="{{ $cardSub }}">{{ __('Material short on return + finished units short at receipt. Below zero = surplus.') }}</p>
                    </div>
                    <button type="button" class="{{ $tableToggle }}" x-on:click="table = ! table" x-text="table ? @js(__('Show chart')) : @js(__('Show table'))"></button>
                </div>
                @if (collect($lossSeries)->every(fn ($s) => array_sum(array_map('abs', $s['values'])) == 0))
                    <div x-show="! table" class="py-16 text-center">
                        <flux:icon.check-circle class="mx-auto size-6 text-green-600 dark:text-green-400" />
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No losses recorded in the last 6 months.') }}</p>
                    </div>
                @else
                    <div wire:ignore x-data="stackedBar(@js(['labels' => $lossTrend['months'], 'series' => $lossSeries, 'isolatable' => false, 'targets' => false, 'format' => 'rupiah', 'axisFormat' => 'compactRupiah', 'totalLabel' => __('Total'), 'targetLabel' => '']))">
                        <div x-show="! table" class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
                            <template x-for="s in config.series" :key="s.id">
                                <span class="inline-flex items-center gap-1.5 text-xs text-zinc-700 dark:text-zinc-300">
                                    <span class="size-2.5 rounded-sm" :style="`background: var(--series-${s.slot})`"></span><span x-text="s.name"></span>
                                </span>
                            </template>
                        </div>
                        <div x-show="! table" x-ref="frame" class="relative mt-2 w-full">
                            <svg x-ref="svg" class="block" height="240" role="group" aria-label="{{ __('Losses, last 6 months') }}"></svg>
                            <x-chart-tooltip />
                        </div>
                    </div>
                @endif
                <div x-show="table" x-cloak class="mt-3 overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead><tr class="border-b border-zinc-200 dark:border-white/10"><th class="{{ $th }}"></th>@foreach ($lossTrend['months'] as $label)<th class="{{ $th }} text-right">{{ $label }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach ($lossSeries as $s)
                                <tr class="border-b border-zinc-100 dark:border-white/5"><td class="{{ $td }}">{{ $s['name'] }}</td>@foreach ($s['values'] as $v)<td class="{{ $tdNum }}">{{ $this->formatRupiah($v) }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
