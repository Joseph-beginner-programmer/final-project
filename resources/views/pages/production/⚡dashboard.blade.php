<?php

use App\Enums\ProductType;
use App\Support\Kpi\OngoingWorkOrders;
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
    public function ongoing(): Collection
    {
        return OngoingWorkOrders::all(now());
    }

    #[Computed]
    public function utilisation(): Collection
    {
        return $this->kpis->workCenterUtilisation();
    }

    #[Computed]
    public function planVsActual(): Collection
    {
        return $this->completed
            ->sortByDesc(fn ($row) => abs((float) $row['cost_difference']))
            ->take(10)
            ->sortByDesc(fn ($row) => (float) $row['cost_difference'])
            ->values();
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

    /**
     * @param  list<array{product: \App\Models\Product, to_order: string}>  $materials
     */
    public function requisitionUrl(\App\Models\WorkOrder $workOrder, array $materials): string
    {
        return route('purchasing.requisitions.create', [
            'items' => collect($materials)->map(fn (array $m) => $m['product']->id.':'.\App\Support\Decimal::plain($m['to_order']))->implode(','),
            'needed_by' => $workOrder->planned_start_date->max(today())->toDateString(),
            'for' => $workOrder->wo_number,
        ]);
    }

    public function formatQty(string|float $value): string
    {
        $formatted = number_format((float) $value, 2, ',', '.');

        return str_ends_with($formatted, ',00') ? substr($formatted, 0, -3) : rtrim($formatted, '0');
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

        @php($ongoing = $this->ongoing)
        @php($stateMeta = [
            OngoingWorkOrders::STATE_SHORT => ['label' => __('Short of materials'), 'icon' => 'exclamation-triangle', 'class' => 'text-red-700 dark:text-red-400'],
            OngoingWorkOrders::STATE_READY => ['label' => __('Materials available'), 'icon' => 'check-circle', 'class' => 'text-green-700 dark:text-green-400'],
            OngoingWorkOrders::STATE_AWAITING_RESULT => ['label' => __('Waiting for the production result'), 'icon' => 'clock', 'class' => 'text-amber-700 dark:text-amber-400'],
            OngoingWorkOrders::STATE_RESULT_DRAFT => ['label' => __('Production result in draft'), 'icon' => 'pencil-square', 'class' => 'text-amber-700 dark:text-amber-400'],
        ])
        <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;" x-data="{ all: false }">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Ongoing work orders') }}</h2>
                    <p class="{{ $cardSub }}">{{ __('Draft, released and in progress, earliest start first. Stock is reserved for the earliest work order first.') }}</p>
                </div>
                @if ($ongoing->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-zinc-600 dark:text-zinc-300">
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm" style="background: var(--series-1)"></span>{{ __('Issued') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm" style="background: color-mix(in srgb, var(--series-1) 35%, transparent)"></span>{{ __('In stock') }}</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm" style="background: var(--div-over)"></span>{{ __('Short') }}</span>
                    </div>
                @endif
            </div>

            @if ($ongoing->isEmpty())
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">
                    <span>{{ __('No work orders are open right now.') }}</span>
                    <a href="{{ route('production.work-orders.create') }}" wire:navigate class="text-xs font-medium text-accent hover:underline underline-offset-2">{{ __('Create work order') }}</a>
                </div>
            @else
                <div class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-4">
                    @foreach ($ongoing as $i => $row)
                        @php($wo = $row['work_order'])
                        @php($meta = $stateMeta[$row['state']])
                        <div wire:key="ongoing-{{ $wo->id }}" @if ($i >= 6) x-show="all" x-cloak @endif class="{{ $card }} min-w-0">
                            <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="{{ route('production.work-orders.show', $wo) }}" wire:navigate class="font-data text-sm font-semibold text-accent hover:underline underline-offset-2">{{ $wo->wo_number }}</a>
                                        <span class="rounded-full border border-zinc-200 dark:border-white/10 px-2 py-0.5 text-[11px] text-zinc-600 dark:text-zinc-300">{{ $wo->status->label() }}</span>
                                        @if ($row['overdue'])
                                            <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 text-[11px] font-medium text-red-700 dark:text-red-400"><flux:icon.exclamation-triangle variant="micro" class="size-3" />{{ __('Late') }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $wo->product->product_name }} <span class="font-data text-zinc-500 dark:text-zinc-400">× {{ $this->formatQty($wo->quantity_target) }} {{ $wo->product->unit_of_measure }}</span></p>
                                    <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $wo->planned_start_date->translatedFormat('d M') }} – {{ $wo->planned_end_date->translatedFormat('d M Y') }}</p>
                                </div>
                                <span class="inline-flex items-center gap-1 text-xs font-medium {{ $meta['class'] }}"><flux:icon :name="$meta['icon']" variant="micro" class="size-4" />{{ $meta['label'] }}</span>
                            </div>

                            <div class="mt-4 space-y-3">
                                @foreach ($row['materials'] as $m)
                                    @php($planned = max((float) $m['planned'], 0.0001))
                                    @php($unit = $m['product']->unit_of_measure)
                                    @php($isShort = bccomp($m['short'], '0', 2) > 0)
                                    <div>
                                        <div class="flex items-baseline justify-between gap-3 text-xs">
                                            <span class="truncate text-zinc-800 dark:text-zinc-200">{{ $m['product']->product_name }}</span>
                                            <span class="shrink-0 font-data tabular-nums text-zinc-500 dark:text-zinc-400"><span class="text-zinc-900 dark:text-white">{{ $this->formatQty($m['issued']) }}</span> / {{ $this->formatQty($m['planned']) }} {{ $unit }}</span>
                                        </div>
                                        <div class="mt-1.5 flex h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/5" role="img" aria-label="{{ __(':name: :issued issued, :covered in stock, :short short of :planned', ['name' => $m['product']->product_name, 'issued' => $this->formatQty($m['issued']), 'covered' => $this->formatQty($m['covered']), 'short' => $this->formatQty($m['short']), 'planned' => $this->formatQty($m['planned'])]) }}">
                                            <div class="h-full" style="width: {{ (float) $m['issued'] / $planned * 100 }}%; background: var(--series-1)"></div>
                                            <div class="h-full" style="width: {{ (float) $m['covered'] / $planned * 100 }}%; background: color-mix(in srgb, var(--series-1) 35%, transparent)"></div>
                                            <div class="h-full" style="width: {{ (float) $m['short'] / $planned * 100 }}%; background: var(--div-over)"></div>
                                        </div>
                                        @if ($isShort)
                                            @php($isRaw = $m['product']->type === \App\Enums\ProductType::RawMaterial)
                                            @php($hasIncoming = bccomp($m['incoming'], '0', 2) > 0)
                                            @php($toOrder = bccomp($m['to_order'], '0', 2) > 0)
                                            <p class="mt-1 text-[11px] text-zinc-600 dark:text-zinc-300">
                                                <span class="font-medium text-red-700 dark:text-red-400">{{ __('Short :qty :unit', ['qty' => $this->formatQty($m['short']), 'unit' => $unit]) }}</span>
                                                @if ($hasIncoming)
                                                    · {{ $isRaw ? __(':qty :unit on order (:from)', ['qty' => $this->formatQty($m['incoming']), 'unit' => $unit, 'from' => implode(', ', $m['incoming_from'])]) : __(':from making :qty :unit', ['qty' => $this->formatQty($m['incoming']), 'unit' => $unit, 'from' => implode(', ', $m['incoming_from'])]) }}
                                                @endif
                                                @if ($toOrder)
                                                    · {{ $hasIncoming ? __('still :qty :unit missing', ['qty' => $this->formatQty($m['to_order']), 'unit' => $unit]) : ($isRaw ? __('nothing on order') : __('no work order is making it')) }}
                                                    @if ($isRaw)
                                                        @can('requisitions.create')
                                                            · <a href="{{ $this->requisitionUrl($wo, [$m]) }}" wire:navigate class="font-medium text-accent hover:underline underline-offset-2">{{ __('Request purchase') }}</a>
                                                        @endcan
                                                    @else
                                                        @can('create', \App\Models\WorkOrder::class)
                                                            · <a href="{{ route('production.work-orders.create', ['product' => $m['product']->id, 'quantity' => \App\Support\Decimal::plain($m['to_order']), 'for' => $wo->wo_number]) }}" wire:navigate class="font-medium text-accent hover:underline underline-offset-2">{{ __('Create work order') }}</a>
                                                        @endcan
                                                    @endif
                                                @endif
                                            </p>
                                        @elseif (bccomp($m['covered'], '0', 2) > 0)
                                            <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('In stock for the remaining :qty :unit', ['qty' => $this->formatQty($m['covered']), 'unit' => $unit]) }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            @php($rawToOrder = $row['materials']->filter(fn ($m) => $m['product']->type === \App\Enums\ProductType::RawMaterial && bccomp($m['to_order'], '0', 2) > 0))
                            @if ($rawToOrder->count() > 1)
                                @can('requisitions.create')
                                    <div class="mt-4 border-t border-zinc-100 dark:border-white/5 pt-3">
                                        <a href="{{ $this->requisitionUrl($wo, $rawToOrder->all()) }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-medium text-accent hover:underline underline-offset-2"><flux:icon.clipboard-document-list variant="micro" class="size-4" />{{ __('Request all :count missing materials', ['count' => $rawToOrder->count()]) }}</a>
                                    </div>
                                @endcan
                            @endif
                            @if (in_array($row['state'], [OngoingWorkOrders::STATE_AWAITING_RESULT, OngoingWorkOrders::STATE_RESULT_DRAFT], true))
                                <div class="mt-4 border-t border-zinc-100 dark:border-white/5 pt-3">
                                    @if ($row['state'] === OngoingWorkOrders::STATE_RESULT_DRAFT)
                                        <a href="{{ route('production.results.edit', $wo->productionResult) }}" wire:navigate class="text-xs font-medium text-accent hover:underline underline-offset-2">{{ __('Open result draft') }}</a>
                                    @else
                                        <a href="{{ route('production.results.create', $wo) }}" wire:navigate class="text-xs font-medium text-accent hover:underline underline-offset-2">{{ __('Record result') }}</a>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($ongoing->count() > 6)
                    <button type="button" x-on:click="all = ! all" class="mt-3 text-xs font-medium text-accent hover:underline underline-offset-2 cursor-pointer" x-text="all ? @js(__('Show fewer')) : @js(__('Show all :count', ['count' => $ongoing->count()]))"></button>
                @endif
            @endif
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

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6 motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
            @php($rows = $this->planVsActual)
            @php($overCount = $this->completed->where('over_plan', true)->count())
            <div wire:key="pva-{{ $key }}" class="{{ $card }} min-w-0" x-data="{ table: false }">
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
                    @php($scale = max(1, $rows->max(fn ($r) => max((float) $r['planned_cost'], (float) $r['actual_cost']))))
                    <div x-show="! table">
                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-[11px] text-zinc-600 dark:text-zinc-300">
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm" style="background: var(--series-1)"></span>{{ __('Actual') }}</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-3 w-0.5 rounded-full bg-zinc-900 dark:bg-white"></span>{{ __('Planned') }}</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm" style="background: var(--div-over)"></span>{{ __('Over plan') }}</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm" style="background: color-mix(in srgb, var(--series-1) 22%, transparent)"></span>{{ __('Under plan') }}</span>
                        </div>
                        <div class="mt-3 -mx-2 divide-y divide-zinc-100 dark:divide-white/5">
                            @foreach ($rows as $row)
                                @php($wo = $row['result']->workOrder)
                                @php($planned = (float) $row['planned_cost'])
                                @php($actual = (float) $row['actual_cost'])
                                @php($diff = (float) $row['cost_difference'])
                                <a wire:key="pva-{{ $key }}-{{ $row['result']->id }}" href="{{ route('production.work-orders.show', $wo) }}" wire:navigate class="group block rounded-md px-2 py-2.5 hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                    <div class="flex items-baseline justify-between gap-3">
                                        <span class="min-w-0 truncate text-xs">
                                            <span class="font-data font-medium text-accent group-hover:underline underline-offset-2">{{ $wo->wo_number }}</span>
                                            <span class="text-zinc-600 dark:text-zinc-300">· {{ $wo->product->product_name }}</span>
                                            @if ($row['materials_over_plan'] > 0)
                                                <span class="ms-1 inline-flex items-center gap-0.5 text-[10px] text-amber-700 dark:text-amber-400"><flux:icon.cube variant="micro" class="size-3" />{{ __(':count over plan', ['count' => $row['materials_over_plan']]) }}</span>
                                            @endif
                                        </span>
                                        <span class="shrink-0 font-data text-xs tabular-nums whitespace-nowrap">
                                            <span class="text-zinc-500 dark:text-zinc-400">{{ $this->formatRupiah($actual) }}</span>
                                            <span class="ms-1.5 font-semibold {{ $diff > 0 ? 'text-red-700 dark:text-red-400' : ($diff < 0 ? 'text-green-700 dark:text-green-400' : 'text-zinc-500 dark:text-zinc-400') }}">{{ $diff > 0 ? '+' : '' }}{{ $diff == 0 ? __('On plan') : $this->formatRupiah($diff) }}</span>
                                        </span>
                                    </div>
                                    <div class="relative mt-2 h-3" role="img" aria-label="{{ $wo->wo_number }}: {{ __(':actual actual vs :planned planned', ['actual' => $this->formatRupiah($actual), 'planned' => $this->formatRupiah($planned)]) }}">
                                        <div class="absolute inset-y-0 left-0 rounded-sm" style="width: {{ min($actual, $planned) / $scale * 100 }}%; background: var(--series-1)"></div>
                                        @if ($actual > $planned)
                                            <div class="absolute inset-y-0 rounded-e-sm" style="left: {{ $planned / $scale * 100 }}%; width: {{ ($actual - $planned) / $scale * 100 }}%; background: var(--div-over)"></div>
                                        @elseif ($actual < $planned)
                                            <div class="absolute inset-y-0 rounded-e-sm" style="left: {{ $actual / $scale * 100 }}%; width: {{ ($planned - $actual) / $scale * 100 }}%; background: color-mix(in srgb, var(--series-1) 22%, transparent)"></div>
                                        @endif
                                        <div class="absolute -inset-y-1 w-0.5 rounded-full bg-zinc-900 dark:bg-white" style="left: calc({{ $planned / $scale * 100 }}% - 1px)"></div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                        @if ($this->completed->count() > $rows->count())
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
        </div>
    </div>
</section>
