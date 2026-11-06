<?php

use App\Support\Kpi\ExecutiveKpis;
use App\Support\Kpi\SalesKpis;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Sales Dashboard')] class extends Component {
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
    public function kpis(): ExecutiveKpis
    {
        $start = $this->periodStart();

        return new ExecutiveKpis($start, $start->copy()->endOfMonth());
    }

    #[Computed]
    public function revenue(): array
    {
        return $this->kpis->profitTrend();
    }

    #[Computed]
    public function openOrders(): array
    {
        return SalesKpis::openOrders();
    }

    #[Computed]
    public function oldestOpenOrders(): Collection
    {
        return SalesKpis::oldestOpenOrders();
    }

    public function formatRupiah(string|float $amount): string
    {
        $prefix = (float) $amount < 0 ? '−Rp ' : 'Rp ';

        return $prefix.number_format(abs((float) $amount), 0, ',', '.');
    }

    public function formatCompact(float|string $amount): string
    {
        $value = abs((float) $amount);
        $prefix = (float) $amount < 0 ? '−Rp ' : 'Rp ';

        return $prefix.match (true) {
            $value >= 1e9 => number_format($value / 1e9, 2, ',', '.').' M',
            $value >= 1e6 => number_format($value / 1e6, 1, ',', '.').' jt',
            $value >= 1e3 => number_format($value / 1e3, 0, ',', '.').' rb',
            default => number_format($value, 0, ',', '.'),
        };
    }

    public function formatQuantity(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    public function formatPercent(?float $value, int $decimals = 0): string
    {
        return $value === null ? '—' : number_format($value, $decimals, ',', '.').'%';
    }

    public function change(array $values): ?float
    {
        $count = count($values);
        if ($count < 2 || abs($values[$count - 2]) < 0.005) {
            return null;
        }

        return ($values[$count - 1] - $values[$count - 2]) / abs($values[$count - 2]) * 100;
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
@php($tileNote = 'mt-1 text-xs leading-4 text-zinc-500 dark:text-zinc-400')
@php($panelLabel = 'text-xs font-medium text-zinc-500 dark:text-zinc-400')
@php($panelLink = 'text-[11px] font-medium text-accent hover:underline underline-offset-2')
@php($empty = 'py-14 text-center text-sm text-zinc-500 dark:text-zinc-400')
@php($key = $period)

<section class="viz-root w-full max-w-6xl mx-auto">
    <div class="flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Sales Dashboard') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>
        <a href="{{ route('sales.orders.list') }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-medium text-accent hover:underline underline-offset-2">
            {{ __('Open sales orders') }} <flux:icon.arrow-right variant="micro" class="size-3.5" />
        </a>
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
        @php($r = $this->revenue)
        @php($last = count($r['labels']) - 1)
        @php($revenue = $r['revenue'][$last])
        @php($revenueMax = max(1, ...$r['revenue']))
        @php($revenueDelta = $this->change($r['revenue']))
        @php($monthLabel = $this->kpis->start->translatedFormat('F Y'))
        @php($open = $this->openOrders)
        @php($byStatus = $open['by_status']->keyBy(fn ($row) => $row['status']->value))

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-12 gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
            <div class="{{ $card }} lg:col-span-7 flex flex-col" wire:key="revenue-{{ $key }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="{{ $panelLabel }}">{{ __('Sales, :month', ['month' => $monthLabel]) }}</p>
                        <p class="mt-1.5 font-display text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white" title="{{ $this->formatRupiah($revenue) }}">{{ $this->formatCompact($revenue) }}</p>
                        <p class="mt-1 flex flex-wrap items-center gap-x-1.5 text-[11px]">
                            @if ($revenueDelta !== null)
                                <span class="inline-flex items-center gap-0.5 font-semibold {{ $revenueDelta >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                                    <flux:icon :name="$revenueDelta >= 0 ? 'arrow-trending-up' : 'arrow-trending-down'" variant="micro" class="size-3.5" />{{ $this->formatPercent(abs($revenueDelta)) }}
                                </span>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('vs last month · excluding VAT') }}</span>
                            @else
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Excluding VAT') }}</span>
                            @endif
                        </p>
                    </div>
                    <a href="{{ route('accounting.sales-invoices.list') }}" wire:navigate class="{{ $panelLink }} shrink-0">{{ __('Invoices') }}</a>
                </div>

                <div class="mt-5 grid h-28 grid-cols-6 items-end gap-3 sm:gap-5" role="img" aria-label="{{ __('Sales, last 6 months') }}">
                    @foreach ($r['revenue'] as $i => $value)
                        <div class="flex h-full flex-col items-center justify-end gap-1.5" title="{{ $r['labels'][$i] }}: {{ $this->formatRupiah($value) }}">
                            <span class="font-data text-[10px] tabular-nums leading-none {{ $i === $last ? 'font-semibold text-zinc-900 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $value > 0 ? $this->formatCompact($value) : '' }}</span>
                            <div class="w-full max-w-12 rounded {{ $i === $last ? '' : 'bg-zinc-200 dark:bg-zinc-700/80' }}" style="height: {{ max(3, $value / $revenueMax * 72) }}px; {{ $i === $last ? 'background: var(--series-1)' : '' }}"></div>
                            <span class="text-[11px] leading-none {{ $i === $last ? 'font-semibold text-zinc-800 dark:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $r['labels'][$i] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="lg:col-span-5 grid grid-cols-1 gap-4">
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-2 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 divide-x divide-y sm:divide-y-0 lg:divide-y divide-zinc-100 dark:divide-white/5">
                    <div class="px-4 sm:px-5 py-4">
                        <p class="{{ $tileLabel }}">{{ __('Open sales orders') }}</p>
                        <p class="mt-1.5 font-display text-2xl font-semibold text-zinc-900 dark:text-white">{{ $this->formatQuantity($open['count']) }}</p>
                        <p class="{{ $tileNote }}">{{ __('Confirmed, awaiting delivery.') }}</p>
                    </div>
                    <div class="px-4 sm:px-5 py-4">
                        <p class="{{ $tileLabel }}">{{ __('Still to deliver') }}</p>
                        <p class="mt-1.5 font-display text-2xl font-semibold text-zinc-900 dark:text-white">{{ $this->formatCompact($open['value']) }}</p>
                        <p class="{{ $tileNote }}">{{ __('Value not yet delivered.') }}</p>
                    </div>
                    <div class="px-4 sm:px-5 py-4">
                        <p class="{{ $tileLabel }}">{{ __('Confirmed') }}</p>
                        <p class="mt-1.5 font-display text-2xl font-semibold text-zinc-900 dark:text-white">{{ $this->formatQuantity($byStatus['confirmed']['count'] ?? 0) }}</p>
                        <p class="{{ $tileNote }}">{{ __('Not yet started delivering.') }}</p>
                    </div>
                    <div class="px-4 sm:px-5 py-4">
                        <p class="{{ $tileLabel }}">{{ __('Partially delivered') }}</p>
                        <p class="mt-1.5 font-display text-2xl font-semibold text-zinc-900 dark:text-white">{{ $this->formatQuantity($byStatus['partially_delivered']['count'] ?? 0) }}</p>
                        <p class="{{ $tileNote }}">{{ __('Some lines already shipped.') }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 {{ $card }} motion-safe:animate-fade-slide-up" style="animation-delay: 60ms;">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="{{ $cardTitle }}">{{ __('Oldest open orders') }}</h3>
                    <p class="{{ $cardSub }}">{{ __('Confirmed or partially delivered orders, oldest first.') }}</p>
                </div>
                <a href="{{ route('sales.orders.list') }}" wire:navigate class="{{ $panelLink }}">{{ __('All orders') }}</a>
            </div>

            @if ($this->oldestOpenOrders->isEmpty())
                <p class="{{ $empty }}">{{ __('No open sales orders right now.') }}</p>
            @else
                <div class="mt-4 divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach ($this->oldestOpenOrders as $row)
                        <a href="{{ route('sales.orders.show', $row['order']) }}" wire:navigate class="group flex items-center justify-between gap-3 py-2.5 text-sm">
                            <span class="min-w-0">
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $row['order']->so_number }}</span>
                                <span class="ms-2 text-zinc-500 dark:text-zinc-400 truncate">{{ $row['order']->customer->customer_name }}</span>
                                <span class="ms-2 text-[11px] text-zinc-400 dark:text-zinc-500">{{ $row['order']->status->label() }}</span>
                            </span>
                            <span class="shrink-0 flex items-center gap-2">
                                <span class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatRupiah($row['value']) }}</span>
                                <flux:icon.chevron-right variant="micro" class="size-4 text-zinc-400 group-hover:text-accent" />
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
