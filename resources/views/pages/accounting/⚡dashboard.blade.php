<?php

use App\Support\Kpi\ExecutiveKpis;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Accounting Dashboard')] class extends Component {
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
    public function balances(): array
    {
        return $this->kpis->balanceTrend();
    }

    #[Computed]
    public function incomeStatement(): array
    {
        return $this->kpis->incomeStatement();
    }

    #[Computed]
    public function receivables(): array
    {
        return $this->kpis->receivablesAging();
    }

    #[Computed]
    public function payables(): array
    {
        return $this->kpis->payablesAging();
    }

    #[Computed]
    public function attention(): array
    {
        return ExecutiveKpis::attention(today());
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

    public function bucketColor(string $key): string
    {
        return match ($key) {
            'current' => 'var(--series-3)',
            '1_30' => 'var(--series-4)',
            '31_60' => 'color-mix(in srgb, var(--div-over) 55%, var(--series-4) 45%)',
            '61_90' => 'color-mix(in srgb, var(--div-over) 80%, transparent)',
            default => 'var(--div-over)',
        };
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
@php($sectionTitle = 'text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300')
@php($panelLabel = 'text-xs font-medium text-zinc-500 dark:text-zinc-400')
@php($panelLink = 'text-[11px] font-medium text-accent hover:underline underline-offset-2')
@php($key = $period)

<section class="viz-root w-full max-w-6xl mx-auto">
    <div class="flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Accounting Dashboard') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>
        <a href="{{ route('accounting.reports.income-statement') }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-medium text-accent hover:underline underline-offset-2">
            {{ __('Financial statements') }} <flux:icon.arrow-right variant="micro" class="size-3.5" />
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
        @php($b = $this->balances)
        @php($last = count($b['labels']) - 1)
        @php($cash = $b['cash'][$last])
        @php($cashChange = $cash - ($b['cash'][$last - 1] ?? 0))
        @php($cashMax = max(1, ...array_map('abs', $b['cash'])))
        @php($monthLabel = $this->kpis->start->translatedFormat('F Y'))
        @php($it = $this->incomeStatement['totals'])
        @php($isRevenue = (float) $it['revenue']['month'])
        @php($isCost = (float) $it['cost_of_sales']['month'])
        @php($isGross = (float) $it['gross_profit']['month'])
        @php($isNet = (float) $it['net_profit']['month'])

        <h2 class="mt-7 {{ $sectionTitle }} motion-safe:animate-fade-slide-up" style="animation-delay: 30ms;">{{ __('Cash & profit') }}</h2>
        <div class="mt-3 grid grid-cols-1 lg:grid-cols-12 gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
            <div class="{{ $card }} lg:col-span-7 flex flex-col" wire:key="cash-{{ $key }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="{{ $panelLabel }}">{{ __('Cash & bank, :month', ['month' => $monthLabel]) }}</p>
                        <p class="mt-1.5 font-display text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white" title="{{ $this->formatRupiah($cash) }}">{{ $this->formatCompact($cash) }}</p>
                        <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400"><span class="font-semibold {{ $cashChange >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">{{ $cashChange >= 0 ? '+' : '−' }}{{ $this->formatCompact(abs($cashChange)) }}</span> {{ __('this month') }}</p>
                    </div>
                    <a href="{{ route('accounting.reports.balance-sheet', ['period' => $period]) }}" wire:navigate class="{{ $panelLink }} shrink-0">{{ __('Balance sheet') }}</a>
                </div>

                <div class="mt-5 grid h-24 grid-cols-6 items-end gap-3 sm:gap-5" role="img" aria-label="{{ __('Cash & bank, last 6 months') }}">
                    @foreach ($b['cash'] as $i => $value)
                        <div class="flex h-full flex-col items-center justify-end gap-1.5" title="{{ $b['labels'][$i] }}: {{ $this->formatRupiah($value) }}">
                            <span class="font-data text-[10px] tabular-nums leading-none {{ $i === $last ? 'font-semibold text-zinc-900 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->formatCompact($value) }}</span>
                            <div class="w-full max-w-12 rounded {{ $i === $last ? '' : 'bg-zinc-200 dark:bg-zinc-700/80' }}" style="height: {{ max(3, abs($value) / $cashMax * 64) }}px; {{ $i === $last ? 'background: var(--series-1)' : '' }}"></div>
                            <span class="text-[11px] leading-none {{ $i === $last ? 'font-semibold text-zinc-800 dark:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $b['labels'][$i] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="{{ $card }} lg:col-span-5 flex flex-col" wire:key="pl-{{ $key }}">
                <div class="flex items-start justify-between gap-3">
                    <p class="{{ $panelLabel }}">{{ __('P&L, :month', ['month' => $monthLabel]) }}</p>
                    <a href="{{ route('accounting.reports.income-statement', ['period' => $period]) }}" wire:navigate class="{{ $panelLink }} shrink-0">{{ __('Income statement') }}</a>
                </div>
                @if ($isRevenue == 0 && $isCost == 0)
                    <p class="mt-6 text-xs text-zinc-500 dark:text-zinc-400">{{ __('No income or expenses in this month.') }}</p>
                @else
                    <dl class="mt-3 space-y-2.5">
                        @foreach ([[__('Revenue'), $isRevenue], [__('Cost of sales'), -$isCost], [__('Gross profit'), $isGross], [__('Net profit'), $isNet]] as [$name, $value])
                            <div class="flex items-baseline justify-between gap-3 {{ $loop->last ? 'border-t border-zinc-100 dark:border-white/5 pt-2.5' : '' }}">
                                <dt class="text-xs {{ $loop->last ? 'font-semibold text-zinc-800 dark:text-zinc-100' : 'text-zinc-600 dark:text-zinc-300' }}">{{ $name }}</dt>
                                <dd class="font-data text-sm tabular-nums {{ $value < 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-900 dark:text-white' }} {{ $loop->last ? 'font-semibold' : '' }}">{{ $this->formatCompact($value) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if ($this->kpis->overheadOpen())
                        <p class="mt-3 flex items-center gap-1.5 text-[11px] text-amber-700 dark:text-amber-400"><flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" />{{ __('Provisional: overhead not closed') }}</p>
                    @endif
                @endif
            </div>
        </div>

        <h2 class="mt-8 {{ $sectionTitle }} motion-safe:animate-fade-slide-up" style="animation-delay: 50ms;">{{ __('Receivables & payables') }}</h2>
        <div class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 60ms;">
            @foreach ([['label' => __('Piutang Usaha'), 'data' => $this->receivables, 'href' => route('accounting.sales-invoices.list')], ['label' => __('Utang Usaha'), 'data' => $this->payables, 'href' => route('accounting.purchase-invoices.list')]] as $panel)
                @php($d = $panel['data'])
                <div class="{{ $card }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="{{ $panelLabel }}">{{ $panel['label'] }}</p>
                            <p class="mt-1.5 font-display text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white" title="{{ $this->formatRupiah($d['total']) }}">{{ $this->formatCompact($d['total']) }}</p>
                            <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">
                                @if ($d['overdue_count'] > 0)
                                    <span class="font-semibold text-red-700 dark:text-red-400">{{ $this->formatCompact($d['overdue']) }}</span>
                                    {{ trans_choice('· :count invoice overdue|· :count invoices overdue', $d['overdue_count'], ['count' => $d['overdue_count']]) }}
                                @else
                                    {{ __('Nothing overdue.') }}
                                @endif
                            </p>
                        </div>
                        <a href="{{ $panel['href'] }}" wire:navigate class="{{ $panelLink }} shrink-0">{{ __('Invoices') }}</a>
                    </div>

                    @if ($d['total'] > 0)
                        <div class="mt-4 flex h-2.5 gap-0.5 overflow-hidden rounded" role="img" aria-label="{{ $panel['label'] }}">
                            @foreach ($d['buckets'] as $bucket)
                                @if ($bucket['amount'] > 0)
                                    <div class="h-full first:rounded-s last:rounded-e" style="width: {{ $bucket['amount'] / $d['total'] * 100 }}%; background: {{ $this->bucketColor($bucket['key']) }}" title="{{ $this->bucketLabel($bucket['key']) }}: {{ $this->formatRupiah($bucket['amount']) }}"></div>
                                @endif
                            @endforeach
                        </div>
                        <dl class="mt-3 grid grid-cols-5 gap-2">
                            @foreach ($d['buckets'] as $bucket)
                                <div class="min-w-0">
                                    <dt class="flex items-center gap-1 text-[10px] text-zinc-500 dark:text-zinc-400"><span class="size-1.5 shrink-0 rounded-sm" style="background: {{ $this->bucketColor($bucket['key']) }}"></span><span class="truncate">{{ $this->bucketLabel($bucket['key']) }}</span></dt>
                                    <dd class="mt-0.5 font-data text-xs tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatCompact($bucket['amount']) }}</dd>
                                </div>
                            @endforeach
                        </dl>

                        @if ($d['top']->isNotEmpty())
                            <div class="mt-4 border-t border-zinc-100 dark:border-white/5 pt-3 space-y-1.5">
                                <p class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">{{ __('Most overdue') }}</p>
                                @foreach ($d['top'] as $row)
                                    <div class="flex items-baseline justify-between gap-2 text-xs">
                                        <span class="truncate text-zinc-700 dark:text-zinc-300">{{ $row['name'] }} <span class="text-zinc-400 dark:text-zinc-500">· {{ trans_choice(':count day|:count days', $row['days'], ['count' => $row['days']]) }}</span></span>
                                        <span class="shrink-0 font-data tabular-nums text-red-700 dark:text-red-400">{{ $this->formatCompact($row['amount']) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Nothing outstanding.') }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        @php($a = $this->attention)
        @php($attentionItems = array_filter([
            ['count' => $a['overdue_receivables']['count'], 'label' => __('customer invoices past their due date'), 'href' => route('accounting.sales-invoices.list'), 'tone' => 'red', 'amount' => $a['overdue_receivables']['amount']],
            ['count' => $a['payables_due']['count'], 'label' => __('supplier invoices due within 7 days or overdue'), 'href' => route('accounting.purchase-invoices.list'), 'tone' => 'amber', 'amount' => $a['payables_due']['amount']],
            ['count' => $a['unclosed_overhead'] ? 1 : 0, 'label' => __('month of overhead not yet closed (:month)', ['month' => $a['unclosed_overhead'] ? Carbon::createFromFormat('Y-m', $a['unclosed_overhead'])->translatedFormat('F Y') : '']), 'href' => route('accounting.overhead.index'), 'tone' => 'zinc', 'amount' => null],
            ['count' => $a['unclosed_ppn'] ? 1 : 0, 'label' => __('month of PPN not yet settled (:month)', ['month' => $a['unclosed_ppn'] ? Carbon::createFromFormat('Y-m', $a['unclosed_ppn']['period'])->translatedFormat('F Y') : '']), 'href' => route('accounting.ppn.index'), 'tone' => 'zinc', 'amount' => $a['unclosed_ppn']['amount'] ?? null],
            ['count' => $a['unmapped_roles'], 'label' => __('system accounts not mapped yet'), 'href' => route('accounting.accounts.list'), 'tone' => 'zinc', 'amount' => null],
        ], fn ($item) => $item['count'] > 0))

        <h2 class="mt-8 {{ $sectionTitle }} motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">{{ __('Needs attention') }}</h2>
        <p class="{{ $cardSub }}">{{ __('Right now, whatever month is chosen.') }}</p>
        <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-x-6 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 px-4 py-1.5 motion-safe:animate-fade-slide-up" style="animation-delay: 80ms;">
            @forelse ($attentionItems as $item)
                @php($dot = ['red' => 'bg-red-500', 'amber' => 'bg-amber-500', 'zinc' => 'bg-zinc-400'][$item['tone']])
                <a href="{{ $item['href'] }}" wire:navigate class="group flex items-center justify-between gap-3 border-b border-zinc-100 dark:border-white/5 py-2.5 text-sm last:border-b-0 md:[&:nth-last-child(2):nth-child(odd)]:border-b-0">
                    <span class="flex items-center gap-2.5 text-zinc-700 dark:text-zinc-300">
                        <span class="size-2 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>
                        <span><span class="font-semibold text-zinc-900 dark:text-white">{{ $item['count'] }}</span> {{ $item['label'] }}@if ($item['amount']) <span class="text-zinc-500 dark:text-zinc-400">· {{ $this->formatRupiah($item['amount']) }}</span>@endif</span>
                    </span>
                    <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 text-zinc-400 group-hover:text-accent" />
                </a>
            @empty
                <div class="flex items-center gap-2 py-2.5 text-sm text-zinc-500 dark:text-zinc-400 md:col-span-2">
                    <flux:icon.check-circle variant="micro" class="size-4 text-green-600 dark:text-green-400" />{{ __('Nothing needs attention right now.') }}
                </div>
            @endforelse
        </div>
    </div>
</section>
