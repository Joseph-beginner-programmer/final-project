<?php

use App\Support\Kpi\PurchasingKpis;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Purchasing Dashboard')] class extends Component {
    #[Url]
    public string $period = '';

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
    public function kpis(): PurchasingKpis
    {
        $start = $this->periodStart();

        return new PurchasingKpis($start, $start->copy()->endOfMonth());
    }

    #[Computed]
    public function periodLabel(): string
    {
        return $this->periodStart()->translatedFormat('F Y');
    }

    #[Computed]
    public function attention(): array
    {
        return PurchasingKpis::attention(now());
    }

    #[Computed]
    public function materialStockStatus(): Collection
    {
        return PurchasingKpis::materialStockStatus();
    }

    #[Computed]
    public function spend(): string
    {
        return $this->kpis->spend();
    }

    #[Computed]
    public function totalOrders(): int
    {
        return $this->kpis->totalOrders();
    }

    #[Computed]
    public function supplierTotals(): Collection
    {
        return $this->kpis->supplierTotals();
    }

    #[Computed]
    public function seriesSuppliers(): Collection
    {
        return $this->kpis->seriesSuppliers()->values()->map(fn ($s, $i) => [...$s, 'slot' => $i + 1]);
    }

    #[Computed]
    public function spendTrend(): array
    {
        $data = $this->kpis->spendTrend($this->seriesSuppliers);
        $slots = $this->seriesSuppliers->pluck('slot', 'id');

        return [
            'labels' => $data['months'],
            'overall' => $data['overall'],
            'series' => collect($data['series'])->map(fn ($s) => [...$s, 'slot' => $slots[$s['id']] ?? PurchasingKpis::MAX_SUPPLIER_SERIES + 1])->all(),
        ];
    }

    #[Computed]
    public function onTimeDelivery(): Collection
    {
        return $this->kpis->onTimeDelivery();
    }

    #[Computed]
    public function priceVariance(): Collection
    {
        return $this->kpis->priceVariance();
    }

    public function formatRupiah(string|float $amount): string
    {
        $prefix = (float) $amount < 0 ? '−Rp ' : 'Rp ';

        return $prefix.number_format(abs((float) $amount), 0, ',', '.');
    }

    public function formatQuantity(int $value): string
    {
        return number_format($value, 0, ',', '.');
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
@php($tileLabel = 'min-h-8 text-xs font-medium leading-4 text-zinc-500 dark:text-zinc-400')
@php($tileValue = 'mt-2 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white')
@php($tileNote = 'mt-1 text-xs leading-4 text-zinc-500 dark:text-zinc-400')
@php($tileIconWrap = 'inline-flex size-9 items-center justify-center rounded-lg')
@php($th = 'py-1.5 px-2 text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')
@php($td = 'py-1.5 px-2 text-zinc-700 dark:text-zinc-300')
@php($tdNum = 'py-1.5 px-2 text-right tabular-nums text-zinc-800 dark:text-zinc-200 whitespace-nowrap')
@php($key = $period)

<section class="viz-root w-full max-w-6xl mx-auto">
    <div class="flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Purchasing Dashboard') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>
        @can('reports.purchasing')
            <a href="{{ route('purchasing.reports.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-medium text-accent hover:underline underline-offset-2">
                {{ __('Open reports') }} <flux:icon.arrow-right variant="micro" class="size-3.5" />
            </a>
        @endcan
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-2 motion-safe:animate-fade-slide-up" style="animation-delay: 20ms;">
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

    <div wire:loading.class="opacity-50" wire:target="period,shiftPeriod" class="transition-opacity">
        @php($a = $this->attention)
        @php($overdueCount = $a['overdue']->count())
        <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
            <div class="{{ $card }}">
                <span class="{{ $tileIconWrap }} bg-accent/10 text-accent"><flux:icon.queue-list variant="micro" class="size-5" /></span>
                <p class="{{ $tileLabel }} mt-3">{{ __('Total PO') }}</p>
                <p class="{{ $tileValue }}">{{ $this->formatQuantity($this->totalOrders) }}</p>
                <p class="{{ $tileNote }}">{{ __('Purchase orders created this period.') }}</p>
            </div>
            <div class="{{ $card }}">
                <span class="{{ $tileIconWrap }} bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400"><flux:icon.clock variant="micro" class="size-5" /></span>
                <p class="{{ $tileLabel }} mt-3">{{ __('Pending approval') }}</p>
                <p class="{{ $tileValue }}">{{ $this->formatQuantity($a['pending_approval']) }}</p>
                <p class="{{ $tileNote }}">{{ __('Purchase orders waiting on Manager.') }}</p>
            </div>
            <div class="{{ $card }}">
                <span class="{{ $tileIconWrap }} {{ $overdueCount > 0 ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' : 'bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400' }}"><flux:icon.exclamation-triangle variant="micro" class="size-5" /></span>
                <p class="{{ $tileLabel }} mt-3">{{ __('Overdue deliveries') }}</p>
                <p class="{{ $tileValue }}">{{ $this->formatQuantity($overdueCount) }}</p>
                <p class="{{ $tileNote }}">{{ __('Past their expected delivery date.') }}</p>
            </div>
            <div class="{{ $card }}">
                <span class="{{ $tileIconWrap }} bg-accent/10 text-accent"><flux:icon.clipboard-document-list variant="micro" class="size-5" /></span>
                <p class="{{ $tileLabel }} mt-3">{{ __('Requisitions awaiting purchase') }}</p>
                <p class="{{ $tileValue }}">{{ $this->formatQuantity($a['requisitions_submitted']) }}</p>
                <p class="{{ $tileNote }}">{{ __('Submitted, not yet turned into a PO.') }}</p>
            </div>
            <div class="{{ $card }}">
                <span class="{{ $tileIconWrap }} bg-accent/10 text-accent"><flux:icon.banknotes variant="micro" class="size-5" /></span>
                <p class="{{ $tileLabel }} mt-3">{{ __('Spend this period') }}</p>
                <p class="{{ $tileValue }}">{{ $this->formatRupiah($this->spend) }}</p>
                <p class="{{ $tileNote }}">{{ __(':count invoiced suppliers', ['count' => $this->supplierTotals->count()]) }}</p>
            </div>
            @php($lowStockCount = $this->materialStockStatus->whereIn('status', ['below', 'near'])->count())
            <div class="{{ $card }}">
                <span class="{{ $tileIconWrap }} {{ $lowStockCount > 0 ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' : 'bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400' }}"><flux:icon.cube variant="micro" class="size-5" /></span>
                <p class="{{ $tileLabel }} mt-3">{{ __('Materials needing attention') }}</p>
                <p class="{{ $tileValue }}">{{ $this->formatQuantity($lowStockCount) }}</p>
                <p class="{{ $tileNote }}">{{ __('At or nearing their reorder point.') }}</p>
            </div>
        </div>

        @php($attentionItems = array_filter([
            ['count' => $a['pending_approval'], 'label' => __('purchase orders waiting for approval'), 'href' => route('purchasing.orders.list', ['status' => 'pending_approval']), 'tone' => 'amber'],
            ['count' => $a['overdue']->count(), 'label' => __('purchase orders past their expected delivery date'), 'href' => route('purchasing.orders.list'), 'tone' => 'red'],
            ['count' => $a['rejected'], 'label' => __('rejected purchase orders still sitting unresolved'), 'href' => route('purchasing.orders.list', ['status' => 'rejected']), 'tone' => 'amber'],
            ['count' => $a['requisitions_submitted'], 'label' => __('purchase requisitions submitted, not yet purchased'), 'href' => route('purchasing.requisitions.list'), 'tone' => 'amber'],
            ['count' => $a['draft_invoices'], 'label' => __('purchase invoices still in draft'), 'href' => Gate::allows('purchasing.invoice') ? route('accounting.purchase-invoices.list', ['status' => 'draft']) : null, 'tone' => 'zinc'],
            ['count' => $a['draft_returns'], 'label' => __('purchase returns still in draft'), 'href' => route('purchasing.returns.list'), 'tone' => 'zinc'],
        ], fn ($item) => $item['count'] > 0))

        <div class="mt-6 motion-safe:animate-fade-slide-up" style="animation-delay: 60ms;">
            <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Needs attention') }}</h2>
            <p class="{{ $cardSub }}">{{ __('Right now, across all suppliers.') }}</p>
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
                            @foreach ($a['overdue']->take(6) as $po)
                                <a href="{{ route('purchasing.orders.show', $po) }}" wire:navigate class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 text-[11px] text-red-700 dark:text-red-400 hover:underline">
                                    <span class="font-data">{{ $po->po_number }}</span> · {{ $po->supplier->supplier_name }} · {{ $po->expected_delivery_date->format('d/m') }}
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

        @php($statusMeta = [
            'below' => ['label' => __('Below reorder point'), 'badge' => 'bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20', 'row' => 'bg-red-50/40 dark:bg-red-500/[0.04]'],
            'near' => ['label' => __('Nearing reorder point'), 'badge' => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20', 'row' => ''],
            'ok' => ['label' => __('OK'), 'badge' => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20', 'row' => ''],
        ])
        <div class="mt-6 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Raw material stock status') }}</h2>
                    <p class="{{ $cardSub }}">{{ __('Current stock vs. reorder point, right now. "Nearing" = within one safety-stock buffer of the trigger.') }}</p>
                </div>
                @can('viewAny', \App\Models\Product::class)
                    <a href="{{ route('management.products.safety-stock') }}" wire:navigate class="text-xs font-medium text-accent hover:underline underline-offset-2">{{ __('Calculate safety stock & reorder point') }}</a>
                @endcan
            </div>
            <div class="mt-3 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
                <table class="w-full min-w-[45rem] text-xs border-collapse">
                    <thead>
                        <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                            <th class="{{ $th }}">{{ __('Material') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Current stock') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Safety stock') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Reorder point') }}</th>
                            <th class="{{ $th }}">{{ __('Status') }}</th>
                            <th class="{{ $th }}"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->materialStockStatus as $row)
                            @php($meta = $statusMeta[$row['status']])
                            @php($p = $row['product'])
                            <tr wire:key="stock-{{ $p->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 {{ $meta['row'] }}">
                                <td class="py-2.5 px-3">
                                    <span class="text-zinc-900 dark:text-white">{{ $p->product_name }}</span>
                                    <p class="text-[11px] text-zinc-500 dark:text-zinc-400 font-data">{{ $p->product_code }}</p>
                                </td>
                                <td class="{{ $tdNum }} font-semibold">{{ $this->formatQty($row['current_stock']) }} {{ $p->unit_of_measure }}</td>
                                <td class="py-1.5 px-2 text-right font-data tabular-nums whitespace-nowrap text-zinc-500 dark:text-zinc-400">{{ $this->formatQty($row['safety_stock']) }}</td>
                                <td class="py-1.5 px-2 text-right font-data tabular-nums whitespace-nowrap text-zinc-500 dark:text-zinc-400">{{ $this->formatQty($row['reorder_point']) }}</td>
                                <td class="py-2.5 px-3"><span class="rounded-full border px-2 py-0.5 text-[11px] {{ $meta['badge'] }}">{{ $meta['label'] }}</span></td>
                                <td class="py-2.5 px-3 text-right">
                                    @if ($row['status'] === 'below')
                                        @can('create', \App\Models\PurchaseOrder::class)
                                            <a href="{{ route('purchasing.orders.create', $row['single_supplier_id'] ? ['supplier' => $row['single_supplier_id'], 'product' => $p->id] : []) }}" wire:navigate class="shrink-0 text-xs font-medium text-accent hover:underline underline-offset-2">{{ __('Create PO') }}</a>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active raw materials.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 80ms;">
            @php($trend = $this->spendTrend)
            <div wire:key="spend-{{ $key }}" class="{{ $card }} min-w-0" x-data="{ table: false }">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="{{ $cardTitle }}">{{ __('Spend trend, last 6 months') }}</h2>
                        <p class="{{ $cardSub }}">{{ __('All suppliers; click a supplier to isolate it.') }}</p>
                    </div>
                    <button type="button" class="shrink-0 text-xs font-medium text-accent hover:underline underline-offset-2 cursor-pointer" x-on:click="table = ! table" x-text="table ? @js(__('Show chart')) : @js(__('Show table'))"></button>
                </div>
                <div wire:ignore x-data="lineTrend(@js([...$trend, 'isolatable' => true, 'format' => 'rupiah', 'axisFormat' => 'compactRupiah', 'overallLabel' => __('All suppliers'), 'ariaLabel' => __('Spend trend, last 6 months')]))">
                    <div x-show="! table" class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
                        <span class="inline-flex items-center gap-1.5 text-xs text-zinc-700 dark:text-zinc-300"><span class="h-0.5 w-3.5 rounded-full" style="background: var(--viz-ink-secondary)"></span>{{ __('All suppliers') }}</span>
                        <template x-for="s in config.series" :key="s.id">
                            <button type="button" x-on:click="toggle(s.id)" :aria-pressed="isolated === s.id" class="inline-flex items-center gap-1.5 text-xs cursor-pointer transition-opacity" :class="isolated === s.id ? '' : 'opacity-50'">
                                <span class="h-0.5 w-3.5 rounded-full" :style="`background: var(--series-${s.slot})`"></span>
                                <span class="text-zinc-700 dark:text-zinc-300" x-text="s.name"></span>
                            </button>
                        </template>
                    </div>
                    <div x-show="! table" x-ref="frame" class="relative mt-2 w-full">
                        <svg x-ref="svg" class="block" height="240" role="group" aria-label="{{ __('Spend trend, last 6 months') }}"></svg>
                        <x-chart-tooltip />
                    </div>
                </div>
                <div x-show="table" x-cloak class="mt-3 overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead><tr class="border-b border-zinc-200 dark:border-white/10"><th class="{{ $th }}">{{ __('Supplier') }}</th>@foreach ($trend['labels'] as $label)<th class="{{ $th }} text-right">{{ $label }}</th>@endforeach</tr></thead>
                        <tbody>
                            <tr class="border-b border-zinc-100 dark:border-white/5"><td class="{{ $td }} font-medium">{{ __('All suppliers') }}</td>@foreach ($trend['overall'] as $v)<td class="{{ $tdNum }}">{{ $this->formatRupiah($v) }}</td>@endforeach</tr>
                            @foreach ($trend['series'] as $s)
                                <tr class="border-b border-zinc-100 dark:border-white/5"><td class="{{ $td }}">{{ $s['name'] }}</td>@foreach ($s['values'] as $v)<td class="{{ $tdNum }}">{{ $this->formatRupiah($v) }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6 motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
            <div class="{{ $card }} min-w-0">
                <h2 class="{{ $cardTitle }}">{{ __('On-time delivery, per supplier') }}</h2>
                <p class="{{ $cardSub }}">{{ __('Receipts this period vs. the PO\'s expected delivery date.') }}</p>
                <div class="mt-4 space-y-4">
                    @forelse ($this->onTimeDelivery as $row)
                        <div>
                            <div class="flex items-baseline justify-between gap-3 text-xs">
                                <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ $row['name'] }}</span>
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->formatPercent($row['rate']) }}</span>
                            </div>
                            <div class="mt-1.5 h-2 rounded-full overflow-hidden" style="background: var(--meter-track)" role="img" aria-label="{{ $row['name'] }} {{ $this->formatPercent($row['rate']) }}">
                                <div class="h-full rounded-full" style="width: {{ min(100, $row['rate'] ?? 0) }}%; background: {{ ($row['rate'] ?? 0) < 80 ? 'var(--status-warning)' : 'var(--meter-fill)' }}"></div>
                            </div>
                            <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __(':on of :total deliveries on time', ['on' => $row['on_time'], 'total' => $row['batches']]) }}</p>
                        </div>
                    @empty
                        <p class="py-4 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No deliveries received in this period.') }}</p>
                    @endforelse
                </div>
            </div>

            <div class="{{ $card }} min-w-0">
                <h2 class="{{ $cardTitle }}">{{ __('Price variance, per supplier') }}</h2>
                <p class="{{ $cardSub }}">{{ __('Invoiced price vs. the lot value at receipt (Selisih Harga Pembelian).') }}</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-white/10">
                                <th class="{{ $th }}">{{ __('Supplier') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Variance') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Rate') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->priceVariance as $row)
                                <tr class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                                    <td class="{{ $td }}">{{ $row['name'] }}</td>
                                    <td class="{{ $tdNum }} font-semibold {{ (float) $row['variance'] > 0 ? 'text-red-700 dark:text-red-400' : ((float) $row['variance'] < 0 ? 'text-green-700 dark:text-green-400' : '') }}">{{ (float) $row['variance'] > 0 ? '+' : '' }}{{ $this->formatRupiah($row['variance']) }}</td>
                                    <td class="{{ $tdNum }}">{{ $this->formatPercent($row['rate']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No invoices posted in this period.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Positive = invoiced above the receipt lot value; negative = invoiced below.') }}</p>
                @can('reports.purchasing')
                    <a href="{{ route('purchasing.reports.price-variance') }}" wire:navigate class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-accent hover:underline underline-offset-2">{{ __('Open full report') }}</a>
                @endcan
            </div>
        </div>
    </div>
</section>
