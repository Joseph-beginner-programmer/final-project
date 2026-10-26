<?php

use App\Support\Kpi\ExecutiveKpis;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Management Dashboard')] class extends Component {
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
    public function profit(): array
    {
        return $this->kpis->profitTrend();
    }

    #[Computed]
    public function balances(): array
    {
        return $this->kpis->balanceTrend();
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

    public function formatRupiah(float|string $amount): string
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

    public function formatShort(float|string $amount): string
    {
        $value = abs((float) $amount);
        $sign = (float) $amount < 0 ? '−' : '';

        return $sign.match (true) {
            $value >= 1e9 => number_format($value / 1e9, 1, ',', '.').'M',
            $value >= 1e6 => number_format($value / 1e6, $value >= 1e8 ? 0 : 1, ',', '.').'jt',
            $value >= 1e3 => number_format($value / 1e3, 0, ',', '.').'rb',
            default => number_format($value, 0, ',', '.'),
        };
    }

    public function formatPercent(?float $value, int $decimals = 1): string
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
@php($cardLink = 'shrink-0 text-xs font-medium text-accent hover:underline underline-offset-2')
@php($legendItem = 'inline-flex items-center gap-1.5 text-xs text-zinc-700 dark:text-zinc-300')
@php($empty = 'py-14 text-center text-sm text-zinc-500 dark:text-zinc-400')

@php($p = $this->profit)
@php($b = $this->balances)
@php($last = count($p['labels']) - 1)
@php($monthLabel = $this->kpis->start->translatedFormat('F Y'))
@php($asOfLabel = $this->kpis->asOf()->translatedFormat('d M Y'))
@php($key = $period)

<section class="viz-root w-full max-w-7xl mx-auto">
    <div class="flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Management Dashboard') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('The company at a glance: profit, cash, customers, stock and production. Trends cover the 6 months up to the chosen month.') }}</p>
        </div>
        <flux:button size="sm" variant="ghost" icon="document-chart-bar" :href="route('executive.reports.index')" wire:navigate class="active:scale-[0.95]">{{ __('All reports') }}</flux:button>
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
        @php($ar = $this->receivables)
        @php($ap = $this->payables)
        @php($open = $this->kpis->overheadOpen())
        @php($revenue = $p['revenue'][$last])
        @php($cost = $p['cost_of_sales'][$last])
        @php($opex = $p['gross_profit'][$last] - $p['net_profit'][$last])
        @php($net = $p['net_profit'][$last])
        @php($salesDelta = $this->change($p['revenue']))
        @php($revenueMax = max(1, ...$p['revenue']))
        @php($panelLabel = 'text-xs font-medium text-zinc-500 dark:text-zinc-400')
        @php($panelLink = 'text-[11px] font-medium text-accent hover:underline underline-offset-2')

        <div class="mt-6 grid grid-cols-1 min-[1400px]:grid-cols-12 gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
            <div class="{{ $card }} min-[1400px]:col-span-7 flex flex-col">
                <div>
                    <div class="min-w-0">
                        <p class="{{ $panelLabel }}">{{ __('Sales, :month', ['month' => $monthLabel]) }}</p>
                        <p class="mt-1.5 font-data text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white" title="{{ $this->formatRupiah($revenue) }}">{{ $this->formatCompact($revenue) }}</p>
                        <p class="mt-1 flex flex-wrap items-center gap-x-1.5 text-[11px]">
                            @if ($salesDelta !== null)
                                <span class="inline-flex items-center gap-0.5 font-semibold {{ $salesDelta >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                                    <flux:icon :name="$salesDelta >= 0 ? 'arrow-trending-up' : 'arrow-trending-down'" variant="micro" class="size-3.5" />{{ $this->formatPercent(abs($salesDelta), 0) }}
                                </span>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('vs last month · excluding VAT') }}</span>
                            @else
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Excluding VAT') }}</span>
                            @endif
                        </p>
                    </div>
                </div>

                <div class="mt-5 grid h-28 grid-cols-6 items-end gap-3 sm:gap-5" role="img" aria-label="{{ __('Sales, last 6 months') }}">
                    @foreach ($p['revenue'] as $i => $value)
                        <div class="flex h-full flex-col items-center justify-end gap-1.5" title="{{ $p['labels'][$i] }}: {{ $this->formatRupiah($value) }}">
                            <span class="font-data text-[10px] tabular-nums leading-none {{ $i === $last ? 'font-semibold text-zinc-900 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $value > 0 ? $this->formatCompact($value) : '' }}</span>
                            <div class="w-full max-w-12 rounded {{ $i === $last ? '' : 'bg-zinc-200 dark:bg-zinc-700/80' }}" style="height: {{ max(3, $value / $revenueMax * 72) }}px; {{ $i === $last ? 'background: var(--series-1)' : '' }}"></div>
                            <span class="text-[11px] leading-none {{ $i === $last ? 'font-semibold text-zinc-800 dark:text-zinc-100' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $p['labels'][$i] }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="mt-auto pt-5">
                <div class="border-t border-zinc-100 dark:border-white/5 pt-4">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="{{ $panelLabel }}">{{ __('Where each Rp 100 of sales goes') }}</p>
                        <a href="{{ route('accounting.reports.income-statement', ['period' => $period]) }}" wire:navigate class="{{ $panelLink }}">{{ __('Income statement') }}</a>
                    </div>
                    @if ($revenue > 0)
                        @php($parts = [
                            ['name' => __('Cost of goods sold'), 'value' => $cost, 'color' => 'var(--series-4)'],
                            ['name' => __('Operating expenses'), 'value' => $opex, 'color' => 'var(--viz-baseline)'],
                            ['name' => $net >= 0 ? __('Net profit') : __('Net loss'), 'value' => $net, 'color' => $net >= 0 ? 'var(--series-3)' : 'var(--div-over)'],
                        ])
                        <div class="mt-2.5 flex h-4 gap-0.5 overflow-hidden rounded" role="img" aria-label="{{ __('Cost :cost, expenses :opex, net :net per Rp 100 of sales', ['cost' => round($cost / $revenue * 100), 'opex' => round($opex / $revenue * 100), 'net' => round($net / $revenue * 100)]) }}">
                            @foreach ($parts as $part)
                                @if (abs($part['value']) > 0)
                                    <div class="h-full first:rounded-s last:rounded-e" style="width: {{ abs($part['value']) / (abs($cost) + abs($opex) + abs($net)) * 100 }}%; background: {{ $part['color'] }}"></div>
                                @endif
                            @endforeach
                        </div>
                        <dl class="mt-3 grid grid-cols-3 gap-3">
                            @foreach ($parts as $part)
                                <div class="min-w-0">
                                    <dt class="flex items-center gap-1.5 text-[11px] text-zinc-500 dark:text-zinc-400"><span class="size-2 shrink-0 rounded-sm" style="background: {{ $part['color'] }}"></span><span class="truncate">{{ $part['name'] }}</span></dt>
                                    <dd class="mt-1 font-data text-sm font-semibold tabular-nums {{ $part['value'] < 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">Rp {{ number_format(abs($part['value']) / $revenue * 100, 0, ',', '.') }}</dd>
                                    <dd class="text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400">{{ $this->formatCompact($part['value']) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        @if ($open)
                            <p class="mt-3 flex items-center gap-1.5 text-[11px] text-amber-700 dark:text-amber-400"><flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" />{{ __('Provisional: overhead not closed') }}</p>
                        @endif
                    @else
                        <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ __('No sales in this month.') }}</p>
                    @endif
                </div>
                </div>
            </div>

            @php($cash = $b['cash'][$last])
            @php($cashChange = $cash - ($b['cash'][$last - 1] ?? 0))
            @php($bridge = [
                ['name' => __('Cash & bank now'), 'value' => $cash, 'sign' => '', 'color' => 'var(--series-1)'],
                ['name' => __('Still to collect from customers'), 'value' => $ar['total'], 'sign' => '+', 'color' => 'var(--series-3)'],
                ['name' => __('Still to pay suppliers'), 'value' => -$ap['total'], 'sign' => '−', 'color' => 'var(--div-over)'],
            ])
            @php($projected = $cash + $ar['total'] - $ap['total'])
            @php($bridgeMax = max(1, $cash, $ar['total'], $ap['total'], $projected))
            <div class="min-[1400px]:col-span-5 grid grid-cols-1 sm:max-[1399px]:grid-cols-2 gap-4">
            <div class="{{ $card }} flex flex-col">
                <div class="flex items-baseline justify-between gap-3">
                    <p class="{{ $panelLabel }}">{{ __('Cash & bank') }}</p>
                    <a href="{{ route('accounting.reports.balance-sheet', ['period' => $period]) }}" wire:navigate class="{{ $panelLink }}">{{ __('Balance sheet') }}</a>
                </div>
                <p class="mt-1.5 font-data text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white" title="{{ $this->formatRupiah($cash) }}">{{ $this->formatCompact($cash) }}</p>
                <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400"><span class="font-semibold {{ $cashChange >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">{{ $cashChange >= 0 ? '+' : '−' }}{{ $this->formatCompact(abs($cashChange)) }}</span> {{ __('this month · at :date', ['date' => $asOfLabel]) }}</p>

                <p class="mt-5 {{ $panelLabel }}">{{ __('Once everyone has paid') }}</p>
                <div class="mt-2.5 space-y-2">
                    @foreach ($bridge as $row)
                        <div>
                            <div class="flex items-baseline justify-between gap-2 text-[11px]">
                                <span class="truncate text-zinc-600 dark:text-zinc-300">{{ $row['sign'] }} {{ $row['name'] }}</span>
                                <span class="shrink-0 font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatCompact(abs($row['value'])) }}</span>
                            </div>
                            <div class="mt-1 h-1.5 rounded-full" style="background: var(--viz-grid)">
                                <div class="h-full rounded-full" style="width: {{ abs($row['value']) / $bridgeMax * 100 }}%; background: {{ $row['color'] }}"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-auto pt-3">
                    <div class="flex items-baseline justify-between gap-2 border-t border-zinc-200 dark:border-white/10 pt-2.5">
                        <span class="text-xs font-semibold text-zinc-800 dark:text-zinc-100">= {{ __('Net position') }}</span>
                        <span class="font-data text-sm font-semibold tabular-nums {{ $projected < 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">{{ $this->formatCompact($projected) }}</span>
                    </div>
                </div>
            </div>

            <div class="grid flex-1 grid-cols-1 gap-4">
                @php($share = $ar['total'] > 0 ? $ar['overdue'] / $ar['total'] * 100 : 0)
                @php($circumference = 2 * M_PI * 26)
                <div class="{{ $card }}">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="{{ $panelLabel }}">{{ __('Overdue receivables') }}</p>
                        <a href="{{ route('accounting.sales-invoices.list') }}" wire:navigate class="{{ $panelLink }}">{{ __('Invoices') }}</a>
                    </div>
                    <div class="mt-3 flex items-center gap-3">
                        <div class="relative size-14 shrink-0" role="img" aria-label="{{ __(':share of receivables overdue', ['share' => $this->formatPercent($share, 0)]) }}">
                            <svg viewBox="0 0 64 64" class="size-14 -rotate-90">
                                <circle cx="32" cy="32" r="26" fill="none" stroke-width="7" style="stroke: var(--viz-grid)"></circle>
                                @if ($share > 0)
                                    <circle cx="32" cy="32" r="26" fill="none" stroke-width="7" stroke-linecap="round" stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $circumference * (1 - min(100, $share) / 100) }}" style="stroke: var(--div-over)"></circle>
                                @endif
                            </svg>
                            <span class="absolute inset-0 flex items-center justify-center font-data text-[11px] font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->formatPercent($share, 0) }}</span>
                        </div>
                        <div class="min-w-0">
                            <p class="whitespace-nowrap font-data text-xl font-semibold tabular-nums {{ $ar['overdue'] > 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}" title="{{ $this->formatRupiah($ar['overdue']) }}">{{ $this->formatCompact($ar['overdue']) }}</p>
                            <p class="mt-0.5 text-[11px] leading-snug text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count invoice past due|:count invoices past due', $ar['overdue_count'], ['count' => $ar['overdue_count']]) }}</p>
                        </div>
                    </div>
                    <p class="mt-2.5 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('of :total owed', ['total' => $this->formatCompact($ar['total'])]) }}</p>
                </div>
            </div>
            </div>
        </div>

        @php($is = $this->kpis->incomeStatement())
        @php($it = $is['totals'])
        @php($isRevenue = (float) $it['revenue']['month'])
        @php($isCost = (float) $it['cost_of_sales']['month'])
        @php($isGross = (float) $it['gross_profit']['month'])
        @php($isNet = (float) $it['net_profit']['month'])
        @php($expenseLines = $is['expenses']->map(fn ($line) => ['name' => $line['account']->account_name, 'value' => (float) $line['month']])->filter(fn ($line) => abs($line['value']) >= 0.005)->sortByDesc('value')->values())
        @php($expenseRows = $expenseLines->count() > 4 ? $expenseLines->take(3)->push(['name' => __('Other expenses'), 'value' => $expenseLines->slice(3)->sum('value')]) : $expenseLines)
        @php($steps = [['name' => __('Sales'), 'from' => 0, 'to' => $isRevenue, 'kind' => 'total', 'color' => 'var(--series-1)'], ['name' => __('Cost of goods sold'), 'from' => $isRevenue, 'to' => $isGross, 'kind' => 'minus', 'color' => 'var(--series-4)'], ['name' => __('Gross profit'), 'from' => 0, 'to' => $isGross, 'kind' => 'subtotal', 'color' => $isGross >= 0 ? 'var(--series-3)' : 'var(--div-over)']])
        @php($running = $isGross)
        @foreach ($expenseRows as $row)
            @php($steps[] = ['name' => $row['name'], 'from' => $running, 'to' => $running - $row['value'], 'kind' => 'minus', 'color' => 'var(--viz-baseline)'])
            @php($running -= $row['value'])
        @endforeach
        @php($steps[] = ['name' => $isNet >= 0 ? __('Net profit') : __('Net loss'), 'from' => 0, 'to' => $isNet, 'kind' => 'subtotal', 'color' => $isNet >= 0 ? 'var(--series-3)' : 'var(--div-over)'])
        @php($domainMin = min(0, ...array_merge(array_column($steps, 'from'), array_column($steps, 'to'))))
        @php($domainMax = max(1, ...array_merge(array_column($steps, 'from'), array_column($steps, 'to'))))
        @php($domain = $domainMax - $domainMin ?: 1)
        @php($ytdRevenue = (float) $it['revenue']['ytd'])
        @php($netValues = $p['net_profit'])
        @php($posMax = max(0, ...$netValues))
        @php($negMax = max(0, -min(...$netValues)))
        @php($netSpan = ($posMax + $negMax) ?: 1)
        <div class="mt-6 {{ $card }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="{{ $cardTitle }}">{{ __('Income statement, :month', ['month' => $monthLabel]) }}</h3>
                    <p class="{{ $cardSub }}">{{ __('From sales down to net profit: what each cost takes away along the way.') }}</p>
                </div>
                <a href="{{ route('accounting.reports.income-statement', ['period' => $period]) }}" wire:navigate class="{{ $cardLink }}">{{ __('Full income statement') }}</a>
            </div>

            <div class="mt-5 grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_17rem] gap-6 lg:gap-8">
                @if ($isRevenue == 0 && $isCost == 0 && $expenseLines->isEmpty())
                    <p class="{{ $empty }}">{{ __('No income or expenses in this month.') }}</p>
                @else
                    <div class="space-y-1.5" role="list" aria-label="{{ __('Income statement, :month', ['month' => $monthLabel]) }}">
                        @foreach ($steps as $step)
                            @php($isTotal = $step['kind'] !== 'minus')
                            @php($amount = $step['kind'] === 'minus' ? $step['from'] - $step['to'] : $step['to'])
                            @php($left = (min($step['from'], $step['to']) - $domainMin) / $domain * 100)
                            @php($width = abs($step['to'] - $step['from']) / $domain * 100)
                            <div role="listitem" class="grid grid-cols-[8.5rem_minmax(0,1fr)_6.5rem] sm:grid-cols-[11rem_minmax(0,1fr)_8rem] items-center gap-3 {{ $step['kind'] === 'subtotal' ? 'border-t border-zinc-100 dark:border-white/5 pt-1.5' : '' }}">
                                <span class="truncate text-xs {{ $isTotal ? 'font-semibold text-zinc-900 dark:text-white' : 'ps-3 text-zinc-600 dark:text-zinc-300' }}" title="{{ $step['name'] }}">{{ $step['kind'] === 'minus' ? '− ' : '' }}{{ $step['name'] }}</span>
                                <div class="relative h-5">
                                    @if ($domainMin < 0)
                                        <div class="absolute inset-y-0 w-px" style="left: {{ -$domainMin / $domain * 100 }}%; background: var(--viz-baseline)"></div>
                                    @endif
                                    <div class="absolute inset-y-0.5 rounded-sm" style="left: {{ $left }}%; width: {{ max(0.4, $width) }}%; background: {{ $step['color'] }}"></div>
                                </div>
                                <span class="text-right font-data text-xs tabular-nums whitespace-nowrap {{ $isTotal ? 'font-semibold' : '' }} {{ $amount < 0 && $isTotal ? 'text-red-700 dark:text-red-400' : 'text-zinc-800 dark:text-zinc-200' }}">
                                    {{ $step['kind'] === 'minus' ? '−' : '' }}{{ $this->formatCompact($step['kind'] === 'minus' ? abs($amount) : $amount) }}
                                    @if ($step['kind'] === 'subtotal' && $isRevenue > 0)
                                        <span class="ms-1 font-sans text-[10px] font-normal text-zinc-500 dark:text-zinc-400">{{ $this->formatPercent($amount / $isRevenue * 100, 0) }}</span>
                                    @endif
                                </span>
                            </div>
                        @endforeach
                        @if ($open)
                            <p class="pt-2 flex items-center gap-1.5 text-[11px] text-amber-700 dark:text-amber-400"><flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" />{{ __('Provisional: overhead not closed') }}</p>
                        @endif
                    </div>
                @endif

                <div class="lg:border-s lg:border-zinc-100 lg:dark:border-white/5 lg:ps-8">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Year to date (Jan – :month)', ['month' => $this->kpis->start->translatedFormat('M')]) }}</p>
                    <dl class="mt-2 space-y-1.5 text-xs">
                        @foreach ([[__('Sales'), $it['revenue']['ytd'], null], [__('Gross profit'), $it['gross_profit']['ytd'], $ytdRevenue], [__('Operating expenses'), $it['expenses']['ytd'], null], [__('Net profit'), $it['net_profit']['ytd'], $ytdRevenue]] as [$name, $value, $base])
                            <div class="flex items-baseline justify-between gap-3">
                                <dt class="text-zinc-600 dark:text-zinc-300">{{ $name }}</dt>
                                <dd class="font-data tabular-nums {{ (float) $value < 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-900 dark:text-white' }} {{ $base !== null ? 'font-semibold' : '' }}">
                                    {{ $this->formatCompact($value) }}@if ($base) <span class="font-sans text-[10px] font-normal text-zinc-500 dark:text-zinc-400">{{ $this->formatPercent((float) $value / $base * 100, 0) }}</span>@endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>

                    <p class="mt-5 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Net profit per month') }}</p>
                    <div class="mt-2 grid grid-cols-6 gap-1.5" role="img" aria-label="{{ __('Net profit per month') }}">
                        @foreach ($netValues as $i => $value)
                            <div class="flex flex-col items-center gap-1" title="{{ $p['labels'][$i] }}: {{ $this->formatRupiah($value) }}">
                                @php($headroom = 18)
                                @php($footroom = $negMax > 0 ? 18 : 0)
                                @php($usable = 100 - $headroom - $footroom)
                                @php($baseline = $headroom + $posMax / $netSpan * $usable)
                                @php($barHeight = abs($value) / $netSpan * $usable)
                                <div class="relative h-20 w-full">
                                    <div class="absolute inset-x-0 h-px" style="top: {{ $baseline }}%; background: var(--viz-baseline)"></div>
                                    @if ($value > 0)
                                        <div class="absolute inset-x-1 rounded-t-sm" style="top: {{ $baseline - $barHeight }}%; height: {{ $barHeight }}%; background: {{ $i === $last ? 'var(--series-3)' : 'color-mix(in srgb, var(--series-3) 45%, transparent)' }}"></div>
                                    @elseif ($value < 0)
                                        <div class="absolute inset-x-1 rounded-b-sm" style="top: {{ $baseline }}%; height: {{ $barHeight }}%; background: var(--div-over)"></div>
                                    @endif
                                    <span class="absolute inset-x-0 text-center font-data text-[9px] leading-none tabular-nums whitespace-nowrap {{ $value < 0 ? 'text-red-700 dark:text-red-400' : ($i === $last ? 'font-semibold text-zinc-900 dark:text-white' : 'text-zinc-500 dark:text-zinc-400') }}" style="{{ $value < 0 ? 'top: calc('.($baseline + $barHeight).'% + 3px)' : 'top: calc('.($baseline - $barHeight).'% - 12px)' }}">{{ $this->formatShort($value) }}</span>
                                </div>
                                <span class="text-[10px] leading-none {{ $i === $last ? 'font-semibold text-zinc-800 dark:text-zinc-100' : 'text-zinc-400 dark:text-zinc-500' }}">{{ mb_substr($p['labels'][$i], 0, 3) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        @php($a = $this->attention)
        @php($attentionItems = array_filter([
            ['count' => $a['pending_po'], 'label' => __('purchase orders waiting for your approval'), 'href' => route('purchasing.orders.list'), 'tone' => 'amber', 'amount' => null],
            ['count' => $a['overdue_receivables']['count'], 'label' => __('customer invoices past their due date'), 'href' => route('accounting.sales-invoices.list'), 'tone' => 'red', 'amount' => $a['overdue_receivables']['amount']],
            ['count' => $a['payables_due']['count'], 'label' => __('supplier invoices due within 7 days or overdue'), 'href' => route('accounting.purchase-invoices.list'), 'tone' => 'amber', 'amount' => $a['payables_due']['amount']],
            ['count' => $a['shortages'], 'label' => __('materials short for planned work orders'), 'href' => route('purchasing.requisitions.list'), 'tone' => 'amber', 'amount' => null],
            ['count' => $a['late_work_orders'], 'label' => __('work orders past their planned end date'), 'href' => route('production.work-orders.list'), 'tone' => 'red', 'amount' => null],
            ['count' => $a['unclosed_overhead'] ? 1 : 0, 'label' => __('month of overhead not yet closed (:month)', ['month' => $a['unclosed_overhead'] ? Carbon::createFromFormat('Y-m', $a['unclosed_overhead'])->translatedFormat('F Y') : '']), 'href' => route('accounting.overhead.index'), 'tone' => 'zinc', 'amount' => null],
            ['count' => $a['unmapped_roles'], 'label' => __('system accounts not mapped yet'), 'href' => route('accounting.accounts.list'), 'tone' => 'zinc', 'amount' => null],
        ], fn ($item) => $item['count'] > 0))

        <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 60ms;">
            <h2 class="{{ $sectionTitle }}">{{ __('Needs attention') }}</h2>
            <p class="{{ $cardSub }}">{{ __('Right now, whatever month is chosen.') }}</p>
            <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-x-6 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 px-4 py-1.5">
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

        <h2 class="mt-10 {{ $sectionTitle }} motion-safe:animate-fade-slide-up" style="animation-delay: 80ms;">{{ __('Finance') }}</h2>
        @if ($this->kpis->overheadOpen())
            <div class="mt-3 flex items-start gap-2.5 rounded-lg border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 px-4 py-2.5 text-xs text-amber-900 dark:text-amber-200">
                <flux:icon.exclamation-triangle variant="micro" class="mt-px size-4 shrink-0" />
                <span>{{ __('The overhead for :month is not closed yet, so its actual bills and depreciation are not in cost of goods sold. Gross and net profit for this month are provisional and will drop when the month is closed.', ['month' => $monthLabel]) }}
                    <a href="{{ route('accounting.overhead.index') }}" wire:navigate class="font-medium underline underline-offset-2">{{ __('Actual overhead') }}</a></span>
            </div>
        @endif
        <div class="mt-3 grid grid-cols-1 lg:grid-cols-3 gap-6 motion-safe:animate-fade-slide-up" style="animation-delay: 80ms;">
            <div wire:key="profit-{{ $key }}" class="{{ $card }} lg:col-span-2 min-w-0">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="{{ $cardTitle }}">{{ __('Sales, cost of goods sold and gross profit') }}</h3>
                        <p class="{{ $cardSub }}">{{ __('Are we growing, and is the margin holding? Hover a month for the margin.') }}</p>
                    </div>
                    <a href="{{ route('accounting.reports.income-statement', ['period' => $period]) }}" wire:navigate class="{{ $cardLink }}">{{ __('Income statement') }}</a>
                </div>
                @if (array_sum(array_map('abs', $p['revenue'])) == 0 && array_sum(array_map('abs', $p['cost_of_sales'])) == 0)
                    <p class="{{ $empty }}">{{ __('No sales in these 6 months.') }}</p>
                @else
                    @php($profitSeries = [
                        ['id' => 'revenue', 'name' => __('Sales'), 'values' => $p['revenue'], 'slot' => 1],
                        ['id' => 'cost', 'name' => __('Cost of goods sold'), 'values' => $p['cost_of_sales'], 'slot' => 4],
                        ['id' => 'gross', 'name' => __('Gross profit'), 'values' => $p['gross_profit'], 'slot' => 3, 'kind' => 'line'],
                    ])
                    <div wire:ignore x-data="groupedBar(@js(['labels' => $p['labels'], 'series' => $profitSeries, 'format' => 'rupiah', 'axisFormat' => 'compactRupiah', 'notes' => [['name' => __('Gross margin'), 'values' => array_map(fn ($m) => $this->formatPercent($m), $p['margin'])]]]))">
                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
                            <template x-for="s in config.series" :key="s.id">
                                <span class="{{ $legendItem }}">
                                    <span :class="s.kind === 'line' ? 'h-0.5 w-3.5 rounded-full' : 'size-2.5 rounded-sm'" :style="`background: var(--series-${s.slot})`"></span><span x-text="s.name"></span>
                                </span>
                            </template>
                        </div>
                        <div x-ref="frame" class="relative mt-2 w-full">
                            <svg x-ref="svg" class="block" height="240" role="group" aria-label="{{ __('Sales, cost of goods sold and gross profit') }}"></svg>
                            <x-chart-tooltip />
                        </div>
                    </div>
                @endif
            </div>

            @php($cashDelta = $b['cash'][$last] - ($b['cash'][$last - 1] ?? 0))
            <div wire:key="cash-{{ $key }}" class="{{ $card }} min-w-0">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="{{ $cardTitle }}">{{ __('Cash position') }}</h3>
                        <p class="{{ $cardSub }}">{{ __('Cash and bank at each month end. Profit is not the same as cash.') }}</p>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span class="whitespace-nowrap font-data text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($b['cash'][$last]) }}</span>
                    <span class="whitespace-nowrap text-[11px] font-medium {{ $cashDelta >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">{{ $cashDelta >= 0 ? '+' : '−' }}{{ $this->formatCompact(abs($cashDelta)) }} {{ __('this month') }}</span>
                </div>
                <div wire:ignore x-data="lineTrend(@js(['labels' => $b['labels'], 'overall' => $b['cash'], 'series' => [], 'isolatable' => false, 'format' => 'rupiah', 'axisFormat' => 'compactRupiah', 'overallLabel' => __('Cash & bank'), 'overallColor' => 'var(--series-1)', 'labelFormat' => 'compactRupiah', 'endLabel' => 'above', 'ariaLabel' => __('Cash position')]))">
                    <div x-ref="frame" class="relative mt-1 w-full">
                        <svg x-ref="svg" class="block" height="220" role="group" aria-label="{{ __('Cash position') }}"></svg>
                        <x-chart-tooltip />
                    </div>
                </div>
            </div>
        </div>

        <h2 class="mt-10 {{ $sectionTitle }}">{{ __('Sales & receivables') }}</h2>
        <div class="mt-3 grid grid-cols-1 lg:grid-cols-3 gap-6">
            @php($margins = $this->kpis->productMargins())
            @php($scale = max(1, $margins->max(fn ($m) => max($m['revenue'], $m['cost'])) ?? 1))
            <div class="{{ $card }} lg:col-span-2 min-w-0">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="{{ $cardTitle }}">{{ __('Gross margin per product') }}</h3>
                        <p class="{{ $cardSub }}">{{ __('Goods delivered in :month: sales value split into cost (FIFO) and profit.', ['month' => $monthLabel]) }}</p>
                    </div>
                </div>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
                    <span class="{{ $legendItem }}"><span class="size-2.5 rounded-sm" style="background: var(--series-4)"></span>{{ __('Cost of goods sold') }}</span>
                    <span class="{{ $legendItem }}"><span class="size-2.5 rounded-sm" style="background: var(--series-3)"></span>{{ __('Gross profit') }}</span>
                    <span class="{{ $legendItem }}"><span class="size-2.5 rounded-sm" style="background: var(--div-over)"></span>{{ __('Loss') }}</span>
                </div>
                <div class="mt-4 space-y-3.5">
                    @forelse ($margins as $row)
                        @php($costWidth = min($row['cost'], $row['revenue']) / $scale * 100)
                        @php($gapWidth = abs($row['revenue'] - $row['cost']) / $scale * 100)
                        <div x-data="{ hover: false }" x-on:mouseenter="hover = true" x-on:mouseleave="hover = false" class="relative">
                            <div class="flex items-baseline justify-between gap-3 text-xs">
                                <span class="truncate font-medium text-zinc-800 dark:text-zinc-200">{{ $row['product'] }}</span>
                                <span class="shrink-0 tabular-nums">
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ $this->formatCompact($row['revenue']) }} ·</span>
                                    <span class="font-semibold {{ ($row['margin'] ?? 0) < 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">{{ $this->formatPercent($row['margin']) }}</span>
                                </span>
                            </div>
                            <div class="mt-1.5 flex h-3.5 gap-0.5" role="img" aria-label="{{ $row['product'] }}: {{ __('margin :margin', ['margin' => $this->formatPercent($row['margin'])]) }}">
                                <div class="h-full rounded-s" style="width: {{ $costWidth }}%; background: var(--series-4)"></div>
                                @if ($gapWidth > 0)
                                    <div class="h-full rounded-e" style="width: {{ $gapWidth }}%; background: {{ $row['profit'] >= 0 ? 'var(--series-3)' : 'var(--div-over)' }}"></div>
                                @endif
                            </div>
                            <div x-show="hover" x-cloak class="pointer-events-none absolute right-0 -top-16 z-10 rounded-lg border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-800 px-3 py-2 text-xs shadow-lg">
                                <p class="font-medium text-zinc-900 dark:text-white">{{ $row['product'] }} · {{ number_format($row['quantity'], 0, ',', '.') }} {{ __('units') }}</p>
                                <p class="text-zinc-500 dark:text-zinc-400">{{ __('Sales') }} <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->formatRupiah($row['revenue']) }}</span> · {{ __('Cost') }} <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->formatRupiah($row['cost']) }}</span> · {{ __('Profit') }} <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->formatRupiah($row['profit']) }}</span></p>
                            </div>
                        </div>
                    @empty
                        <p class="{{ $empty }}">{{ __('Nothing delivered in this month.') }}</p>
                    @endforelse
                </div>
            </div>

            @php($customers = $this->kpis->customerShare())
            <div wire:key="customers-{{ $key }}" class="{{ $card }} min-w-0">
                <h3 class="{{ $cardTitle }}">{{ __('Sales per customer') }}</h3>
                <p class="{{ $cardSub }}">{{ __('Invoiced in :month, excluding VAT. How much do we depend on a few customers?', ['month' => $monthLabel]) }}</p>
                @if ($customers['total'] <= 0)
                    <p class="{{ $empty }}">{{ __('No invoices in this month.') }}</p>
                @else
                    <div wire:ignore x-data="donut(@js(['segments' => $customers['segments'], 'format' => 'rupiah']))" class="mt-4">
                        <div x-ref="frame" class="relative mx-auto w-44">
                            <svg x-ref="svg" class="block" role="group" aria-label="{{ __('Sales per customer') }}"></svg>
                            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                                <span class="text-[10px] uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</span>
                                <span class="font-data text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->formatCompact($customers['total']) }}</span>
                            </div>
                            <x-chart-tooltip />
                        </div>
                        <ul class="mt-4 space-y-1.5">
                            <template x-for="s in config.segments" :key="s.id">
                                <li>
                                    <button type="button" x-on:click="toggle(s.id)" :aria-pressed="isolated === s.id" class="flex w-full items-center justify-between gap-3 text-left text-xs cursor-pointer transition-opacity" :class="isDimmed(s.id) ? 'opacity-40' : ''">
                                        <span class="flex min-w-0 items-center gap-2"><span class="size-2.5 shrink-0 rounded-sm" :style="`background: var(--series-${s.slot})`"></span><span class="truncate text-zinc-700 dark:text-zinc-300" x-text="s.name"></span></span>
                                        <span class="shrink-0 tabular-nums font-medium text-zinc-900 dark:text-white" x-text="new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(s.value / {{ $customers['total'] }} * 100) + '%'"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                @endif
            </div>

            @php($agingLabels = ['current' => __('Not yet due'), '1_30' => __('1–30 days late'), '31_60' => __('31–60 days late'), '61_90' => __('61–90 days late'), 'over_90' => __('Over 90 days late')])
            @php($agingColors = ['current' => 'var(--series-1)', '1_30' => 'var(--status-warning)', '31_60' => 'var(--series-2)', '61_90' => 'var(--div-over)', 'over_90' => 'var(--div-over)'])
            <div class="{{ $card }} lg:col-span-3 min-w-0">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="{{ $cardTitle }}">{{ __('Receivables aging') }}</h3>
                        <p class="{{ $cardSub }}">{{ __('What customers still owe at :date, by how late it is. Who should we chase?', ['date' => $asOfLabel]) }}</p>
                    </div>
                    <a href="{{ route('accounting.sales-invoices.list') }}" wire:navigate class="{{ $cardLink }}">{{ __('Sales invoices') }}</a>
                </div>
                @include('executive._aging', ['aging' => $ar, 'labels' => $agingLabels, 'colors' => $agingColors, 'partyTitle' => __('Most overdue customers'), 'emptyText' => __('No open receivables.')])
            </div>
        </div>

        <h2 class="mt-10 {{ $sectionTitle }}">{{ __('Inventory & purchasing') }}</h2>
        @php($typeNames = ['raw_material' => __('Raw materials'), 'in_process' => __('Work in process'), 'wip' => __('Semi-finished goods'), 'finished_goods' => __('Finished goods')])
        @php($typeSlots = ['raw_material' => 1, 'in_process' => 5, 'wip' => 2, 'finished_goods' => 3])
        <div class="mt-3 grid grid-cols-1 lg:grid-cols-3 gap-6">
            @php($stock = collect($b['inventory_by_type'][$last])->map(fn ($value, $type) => ['id' => $type, 'name' => $typeNames[$type], 'value' => round($value, 2), 'slot' => $typeSlots[$type]])->filter(fn ($s) => $s['value'] > 0)->values()->all())
            <div wire:key="stock-{{ $key }}" class="{{ $card }} min-w-0">
                <h3 class="{{ $cardTitle }}">{{ __('Inventory value by type') }}</h3>
                <p class="{{ $cardSub }}">{{ __('Book value at :date. Where is cash tied up?', ['date' => $asOfLabel]) }}</p>
                @if ($b['inventory'][$last] <= 0)
                    <p class="{{ $empty }}">{{ __('No inventory on the books.') }}</p>
                @else
                    <div wire:ignore x-data="donut(@js(['segments' => $stock, 'format' => 'rupiah']))" class="mt-4">
                        <div x-ref="frame" class="relative mx-auto w-44">
                            <svg x-ref="svg" class="block" role="group" aria-label="{{ __('Inventory value by type') }}"></svg>
                            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
                                <span class="text-[10px] uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</span>
                                <span class="font-data text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->formatCompact($b['inventory'][$last]) }}</span>
                            </div>
                            <x-chart-tooltip />
                        </div>
                        <ul class="mt-4 space-y-1.5">
                            <template x-for="s in config.segments" :key="s.id">
                                <li>
                                    <button type="button" x-on:click="toggle(s.id)" :aria-pressed="isolated === s.id" class="flex w-full items-start justify-between gap-3 text-left text-xs cursor-pointer transition-opacity" :class="isDimmed(s.id) ? 'opacity-40' : ''">
                                        <span class="flex min-w-0 items-start gap-2"><span class="mt-0.5 size-2.5 shrink-0 rounded-sm" :style="`background: var(--series-${s.slot})`"></span><span class="text-zinc-700 dark:text-zinc-300" x-text="s.name"></span></span>
                                        <span class="shrink-0 whitespace-nowrap tabular-nums font-medium text-zinc-900 dark:text-white" x-text="format(s.value)"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                @endif
            </div>

            @php($age = $this->kpis->stockAge())
            @php($ageLabels = ['0_30' => '0–30', '31_60' => '31–60', '61_90' => '61–90', 'over_90' => '> 90'])
            @php($ageSeries = array_map(fn ($s) => [...$s, 'name' => $typeNames[$s['id']], 'slot' => $typeSlots[$s['id']]], $age['series']))
            <div wire:key="age-{{ $key }}" class="{{ $card }} min-w-0">
                <h3 class="{{ $cardTitle }}">{{ __('Stock age') }}</h3>
                <p class="{{ $cardSub }}">{{ __('Value of stock on hand today by days since it arrived. Old stock is money that is not moving.') }}</p>
                @if ($age['total'] <= 0)
                    <p class="{{ $empty }}">{{ __('No stock on hand.') }}</p>
                @else
                    <p class="mt-3 text-xs text-zinc-600 dark:text-zinc-300">
                        <span class="font-semibold {{ ($age['old_share'] ?? 0) > 20 ? 'text-amber-700 dark:text-amber-400' : 'text-zinc-900 dark:text-white' }}">{{ $this->formatPercent($age['old_share'], 0) }}</span> {{ __('of the value is older than 60 days') }}
                    </p>
                    <div wire:ignore x-data="stackedBar(@js(['labels' => array_values($ageLabels), 'series' => $ageSeries, 'isolatable' => true, 'targets' => false, 'format' => 'rupiah', 'axisFormat' => 'compactRupiah', 'totalLabel' => __('Total'), 'targetLabel' => '']))">
                        <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1.5">
                            <template x-for="s in config.series" :key="s.id">
                                <button type="button" x-on:click="toggle(s.id)" :aria-pressed="isolated === s.id" class="{{ $legendItem }} cursor-pointer transition-opacity" :class="isDimmed(s.id) ? 'opacity-40' : ''">
                                    <span class="size-2.5 rounded-sm" :style="`background: var(--series-${s.slot})`"></span><span x-text="s.name"></span>
                                </button>
                            </template>
                        </div>
                        <div x-ref="frame" class="relative mt-2 w-full">
                            <svg x-ref="svg" class="block" height="240" role="group" aria-label="{{ __('Stock age') }}"></svg>
                            <x-chart-tooltip />
                        </div>
                    </div>
                @endif
            </div>

            @php($ap = $this->payables)
            @php($apColors = ['current' => 'var(--series-1)', '1_30' => 'var(--status-warning)', '31_60' => 'var(--series-2)', '61_90' => 'var(--div-over)', 'over_90' => 'var(--div-over)'])
            <div class="{{ $card }} min-w-0">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="{{ $cardTitle }}">{{ __('Payables aging') }}</h3>
                        <p class="{{ $cardSub }}">{{ __('What we still owe suppliers at :date.', ['date' => $asOfLabel]) }}</p>
                    </div>
                    <a href="{{ route('accounting.purchase-invoices.list') }}" wire:navigate class="{{ $cardLink }}">{{ __('Purchase invoices') }}</a>
                </div>
                @include('executive._aging', ['aging' => $ap, 'labels' => $agingLabels, 'colors' => $apColors, 'partyTitle' => null, 'emptyText' => __('No open payables.')])
            </div>
        </div>

        <h2 class="mt-10 {{ $sectionTitle }}">{{ __('Production') }}</h2>
        <div class="mt-3 grid grid-cols-1 lg:grid-cols-3 gap-6 pb-4">
            @php($overhead = $this->kpis->overheadTrend())
            <div wire:key="overhead-{{ $key }}" class="{{ $card }} lg:col-span-2 min-w-0">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="{{ $cardTitle }}">{{ __('Overhead applied vs actual') }}</h3>
                        <p class="{{ $cardSub }}">{{ __('Applied = charged into products at the rate. Actual = real bills and depreciation. A steady gap means the rate is off and product costs are biased.') }}</p>
                    </div>
                    <a href="{{ route('accounting.overhead.index') }}" wire:navigate class="{{ $cardLink }}">{{ __('Actual overhead') }}</a>
                </div>
                @if (array_sum($overhead['applied']) == 0 && array_sum($overhead['actual']) == 0)
                    <p class="{{ $empty }}">{{ __('No overhead in these 6 months.') }}</p>
                @else
                    @php($overheadSeries = [
                        ['id' => 'applied', 'name' => __('Applied'), 'values' => $overhead['applied'], 'slot' => 1],
                        ['id' => 'actual', 'name' => __('Actual'), 'values' => $overhead['actual'], 'slot' => 2],
                    ])
                    @php($overheadNotes = [['name' => __('Difference'), 'values' => array_map(fn ($applied, $actual) => ($applied - $actual >= 0 ? '+' : '').$this->formatRupiah($applied - $actual), $overhead['applied'], $overhead['actual'])]])
                    <div wire:ignore x-data="groupedBar(@js(['labels' => $overhead['labels'], 'series' => $overheadSeries, 'format' => 'rupiah', 'axisFormat' => 'compactRupiah', 'notes' => $overheadNotes]))">
                        <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
                            <template x-for="s in config.series" :key="s.id">
                                <span class="{{ $legendItem }}"><span class="size-2.5 rounded-sm" :style="`background: var(--series-${s.slot})`"></span><span x-text="s.name"></span></span>
                            </template>
                        </div>
                        <div x-ref="frame" class="relative mt-2 w-full">
                            <svg x-ref="svg" class="block" height="240" role="group" aria-label="{{ __('Overhead applied vs actual') }}"></svg>
                            <x-chart-tooltip />
                        </div>
                    </div>
                @endif
            </div>

            @php($pipeline = $this->kpis->workOrderPipeline())
            @php($cost = $this->kpis->costVsPlan())
            @php($stages = [
                ['key' => 'draft', 'name' => __('Draft'), 'slot' => 'var(--viz-baseline)'],
                ['key' => 'released', 'name' => __('Released'), 'slot' => 'var(--series-4)'],
                ['key' => 'in_progress', 'name' => __('In progress'), 'slot' => 'var(--series-1)'],
                ['key' => 'completed', 'name' => __('Completed in :month', ['month' => $this->kpis->start->translatedFormat('F')]), 'slot' => 'var(--series-3)'],
            ])
            @php($pipelineTotal = array_sum($pipeline))
            <div class="{{ $card }} min-w-0 flex flex-col">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="{{ $cardTitle }}">{{ __('Work orders') }}</h3>
                        <p class="{{ $cardSub }}">{{ __('Open work orders right now, plus those completed this month.') }}</p>
                    </div>
                    <a href="{{ route('production.dashboard', ['period' => $period]) }}" wire:navigate class="{{ $cardLink }}">{{ __('Production dashboard') }}</a>
                </div>
                @if ($pipelineTotal > 0)
                    <div class="mt-4 flex h-3 gap-0.5 overflow-hidden rounded-full" role="img" aria-label="{{ __('Work orders by status') }}">
                        @foreach ($stages as $stage)
                            @if ($pipeline[$stage['key']] > 0)
                                <div class="h-full first:rounded-s-full last:rounded-e-full" style="width: {{ $pipeline[$stage['key']] / $pipelineTotal * 100 }}%; background: {{ $stage['slot'] }}"></div>
                            @endif
                        @endforeach
                    </div>
                @endif
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2.5">
                    @foreach ($stages as $stage)
                        <div>
                            <dt class="flex items-center gap-1.5 text-[11px] text-zinc-500 dark:text-zinc-400"><span class="size-2 rounded-sm" style="background: {{ $stage['slot'] }}"></span>{{ $stage['name'] }}</dt>
                            <dd class="mt-0.5 font-data text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $pipeline[$stage['key']] }}</dd>
                        </div>
                    @endforeach
                </dl>
                <div class="mt-auto border-t border-zinc-100 dark:border-white/5 pt-4">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Labor + overhead vs plan, :month', ['month' => $monthLabel]) }}</p>
                    @if ($cost['completed'] > 0)
                        @php($costMax = max($cost['planned'], $cost['actual'], 1))
                        <div class="mt-2 space-y-1.5">
                            @foreach ([[__('Planned'), $cost['planned'], 'var(--viz-baseline)'], [__('Actual'), $cost['actual'], $cost['actual'] > $cost['planned'] ? 'var(--div-over)' : 'var(--series-1)']] as [$name, $value, $color])
                                <div class="grid grid-cols-[3.5rem_minmax(0,1fr)_auto] items-center gap-2 text-[11px]">
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ $name }}</span>
                                    <div class="h-2 rounded-full" style="width: {{ $value / $costMax * 100 }}%; background: {{ $color }}"></div>
                                    <span class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatCompact($value) }}</span>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-2 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __(':over of :total work orders over plan · overrun :amount', ['over' => $cost['over'], 'total' => $cost['completed'], 'amount' => $this->formatRupiah($cost['overrun'])]) }}</p>
                    @else
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('No work orders completed in this period.') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
