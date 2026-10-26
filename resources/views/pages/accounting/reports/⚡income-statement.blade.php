<?php

use App\Support\Reports\IncomeStatementReport;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Income Statement')] class extends Component {
    #[Url]
    public string $period = '';

    public function mount(): void
    {
        Gate::authorize('reports.finance');
        $this->period = ReportPeriod::normalize($this->period);
    }

    public function updatedPeriod(): void
    {
        $this->period = ReportPeriod::normalize($this->period);
    }

    public function shiftPeriod(int $months): void
    {
        $this->period = ReportPeriod::start($this->period)->addMonthsNoOverflow($months)->format('Y-m');
    }

    #[Computed]
    public function report(): IncomeStatementReport
    {
        return new IncomeStatementReport(ReportPeriod::start($this->period), ReportPeriod::end($this->period));
    }

    #[Computed]
    public function data(): array
    {
        return $this->report->build();
    }

    public function amount(string $value): string
    {
        $formatted = number_format(abs((float) $value), 0, ',', '.');

        return bccomp($value, '0', 2) < 0 ? '('.$formatted.')' : $formatted;
    }

    public function percent(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1, ',', '.').'%';
    }
}; ?>

@php($d = $this->data)
@php($t = $d['totals'])
@php($start = $this->report->start)
@php($num = 'w-36 px-4 text-right font-data tabular-nums whitespace-nowrap')
@php($ytdBg = 'bg-zinc-50 dark:bg-white/[0.03]')
@php($sections = [
    ['key' => 'revenue', 'title' => __('Revenue'), 'total' => __('Total revenue'), 'lines' => $d['revenue']],
    ['key' => 'cost_of_sales', 'title' => __('Cost of goods sold'), 'total' => __('Total cost of goods sold'), 'lines' => $d['cost_of_sales']],
    ['key' => 'gross_profit'],
    ['key' => 'expenses', 'title' => __('Operating expenses'), 'total' => __('Total operating expenses'), 'lines' => $d['expenses']],
    ['key' => 'net_profit'],
])

<section class="w-full max-w-4xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route(auth()->user()->role->reportsRoute())" wire:navigate>{{ __('Reports') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Income Statement') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Income Statement') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Did the business make money? Revenue minus the cost of the goods sold and other expenses.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="filled" icon="document-arrow-down" :href="route('accounting.reports.income-statement.export', ['format' => 'pdf', 'period' => $period])" class="active:scale-[0.95]">PDF</flux:button>
            <flux:button size="sm" variant="filled" icon="table-cells" :href="route('accounting.reports.income-statement.export', ['format' => 'csv', 'period' => $period])" class="active:scale-[0.95]">CSV</flux:button>
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

    <div wire:loading.class="opacity-50" wire:target="period,shiftPeriod" class="mt-5 transition-opacity">
        <div class="overflow-x-auto rounded-xl border border-zinc-300 dark:border-white/20 bg-white dark:bg-zinc-900">
            <table class="w-full min-w-[36rem] text-[13px] border-collapse">
                <thead>
                    <tr class="border-b border-zinc-300 dark:border-white/20">
                        <th class="px-4 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Account') }}</th>
                        <th class="{{ $num }} py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-100 font-sans">{{ $start->translatedFormat('F Y') }}</th>
                        <th class="{{ $num }} {{ $ytdBg }} py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 font-sans">{{ $this->report->yearStart()->translatedFormat('M') }} – {{ $start->translatedFormat('M Y') }}</th>
                    </tr>
                </thead>
                @foreach ($sections as $section)
                    @if (in_array($section['key'], ['gross_profit', 'net_profit'], true))
                        @php($isNet = $section['key'] === 'net_profit')
                        @php($value = $t[$section['key']])
                        <tbody>
                            <tr class="border-y-2 border-zinc-300 dark:border-white/20 {{ $isNet ? 'bg-accent/[0.06]' : 'bg-zinc-50 dark:bg-white/[0.03]' }}">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-zinc-900 dark:text-white {{ $isNet ? 'text-sm' : '' }}">{{ $isNet ? (bccomp($value['month'], '0', 2) < 0 ? __('Net loss') : __('Net profit')) : __('Gross profit') }}</p>
                                    <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Margin :month this month · :ytd year to date', ['month' => $this->percent(IncomeStatementReport::margin($value['month'], $t['revenue']['month'])), 'ytd' => $this->percent(IncomeStatementReport::margin($value['ytd'], $t['revenue']['ytd']))]) }}</p>
                                </td>
                                <td class="{{ $num }} py-3 font-semibold {{ $isNet ? 'text-sm' : '' }} {{ bccomp($value['month'], '0', 2) < 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">{{ $this->amount($value['month']) }}</td>
                                <td class="{{ $num }} py-3 font-semibold {{ $isNet ? 'text-sm' : '' }} {{ bccomp($value['ytd'], '0', 2) < 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">{{ $this->amount($value['ytd']) }}</td>
                            </tr>
                        </tbody>
                    @else
                        <tbody>
                            <tr>
                                <td colspan="2" class="px-4 pt-4 pb-1.5 text-xs font-semibold text-zinc-900 dark:text-white">{{ $section['title'] }}</td>
                                <td class="{{ $ytdBg }}"></td>
                            </tr>
                            @forelse ($section['lines'] as $line)
                                @php($account = $line['account'])
                                <tr wire:key="is-{{ $account->id }}" class="group border-b border-zinc-200 dark:border-white/10 hover:bg-accent/[0.04]">
                                    <td class="py-2 pl-8 pr-4">
                                        <a href="{{ route('accounting.reports.ledger', ['account' => $account->id, 'period' => $period]) }}" wire:navigate class="inline-flex items-baseline gap-2.5 hover:underline underline-offset-2 decoration-zinc-300">
                                            <span class="w-10 shrink-0 font-data text-xs text-zinc-400 dark:text-zinc-500 group-hover:text-accent">{{ $account->account_code }}</span>
                                            <span class="text-zinc-800 dark:text-zinc-200">{{ $account->account_name }}</span>
                                        </a>
                                        @if ($account->is_contra)
                                            <span class="ms-1 text-[11px] text-zinc-400 dark:text-zinc-500">({{ __('contra') }})</span>
                                        @endif
                                    </td>
                                    <td class="{{ $num }} py-2 text-zinc-800 dark:text-zinc-200">{{ $this->amount($line['month']) }}</td>
                                    <td class="{{ $num }} {{ $ytdBg }} py-2 text-zinc-500 dark:text-zinc-400">{{ $this->amount($line['ytd']) }}</td>
                                </tr>
                            @empty
                                <tr class="border-b border-zinc-200 dark:border-white/10">
                                    <td class="py-2 pl-8 pr-4 text-xs text-zinc-400 dark:text-zinc-500">{{ __('None this period') }}</td>
                                    <td class="{{ $num }} py-2 text-zinc-400">0</td>
                                    <td class="{{ $num }} {{ $ytdBg }} py-2 text-zinc-400">0</td>
                                </tr>
                            @endforelse
                            <tr>
                                <td class="px-4 py-2 text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ $section['total'] }}</td>
                                <td class="{{ $num }} py-2 font-medium text-zinc-900 dark:text-white">{{ $this->amount($t[$section['key']]['month']) }}</td>
                                <td class="{{ $num }} {{ $ytdBg }} py-2 font-medium text-zinc-700 dark:text-zinc-300">{{ $this->amount($t[$section['key']]['ytd']) }}</td>
                            </tr>
                        </tbody>
                    @endif
                @endforeach
            </table>
        </div>
        <p class="mt-2 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Cost of goods sold includes the production variances and overhead (actual minus applied). Figures in brackets are deductions. Click an account to open its general ledger.') }}</p>
    </div>
</section>
