<?php

use App\Support\Reports\BalanceSheetReport;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Balance Sheet')] class extends Component {
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
    public function data(): array
    {
        return (new BalanceSheetReport(ReportPeriod::end($this->period)))->build();
    }

    public function amount(string $value): string
    {
        $formatted = number_format(abs((float) $value), 0, ',', '.');

        return bccomp($value, '0', 2) < 0 ? '('.$formatted.')' : $formatted;
    }

    public function rupiah(string $value): string
    {
        return 'Rp '.number_format((float) $value, 0, ',', '.');
    }
}; ?>

@php($d = $this->data)
@php($t = $d['totals'])
@php($asOf = ReportPeriod::end($period))
@php($num = 'pl-4 text-right font-data tabular-nums whitespace-nowrap')

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route(auth()->user()->role->reportsRoute())" wire:navigate>{{ __('Reports') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Balance Sheet') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Balance Sheet') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('What the company owns, what it owes, and what belongs to the owners, as of :date.', ['date' => $asOf->translatedFormat('d F Y')]) }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="filled" icon="document-arrow-down" :href="route('accounting.reports.balance-sheet.export', ['format' => 'pdf', 'period' => $period])" class="active:scale-[0.95]">PDF</flux:button>
            <flux:button size="sm" variant="filled" icon="table-cells" :href="route('accounting.reports.balance-sheet.export', ['format' => 'csv', 'period' => $period])" class="active:scale-[0.95]">CSV</flux:button>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400 me-1">{{ __('Month end') }}</span>
        <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="shiftPeriod(-1)" :aria-label="__('Previous month')" class="active:scale-[0.95]" />
        <div class="w-44">
            <flux:input size="sm" type="month" wire:model.live="period" :aria-label="__('Period')" />
        </div>
        <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="shiftPeriod(1)" :aria-label="__('Next month')" class="active:scale-[0.95]" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="period,shiftPeriod" class="mt-5 transition-opacity">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-stretch">
            @foreach ([
                ['title' => __('Assets'), 'blocks' => [['lines' => $d['assets'], 'total' => null]], 'grand' => [__('Total assets'), $t['assets']]],
                ['title' => __('Liabilities and equity'), 'blocks' => [
                    ['heading' => __('Liabilities'), 'lines' => $d['liabilities'], 'total' => [__('Total liabilities'), $t['liabilities']]],
                    ['heading' => __('Equity'), 'lines' => $d['equity'], 'extra' => [__('Current earnings (not yet closed)'), $d['profit']], 'total' => [__('Total equity'), $t['equity']]],
                ], 'grand' => [__('Total liabilities and equity'), $t['liabilities_and_equity']]],
            ] as $side)
                <div class="flex flex-col rounded-xl border border-zinc-300 dark:border-white/20 bg-white dark:bg-zinc-900 overflow-hidden">
                    <h2 class="border-b border-zinc-300 dark:border-white/20 px-5 py-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-800 dark:text-zinc-100">{{ $side['title'] }}</h2>
                    <table class="w-full text-[13px] border-collapse">
                        @foreach ($side['blocks'] as $block)
                            <tbody>
                                @isset($block['heading'])
                                    <tr><td colspan="2" class="px-5 pt-5 pb-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $block['heading'] }}</td></tr>
                                @endisset
                                @forelse ($block['lines'] as $line)
                                    @php($account = $line['account'])
                                    <tr wire:key="bs-{{ $account->id }}" class="group border-b border-zinc-200 dark:border-white/10 hover:bg-accent/[0.04]">
                                        <td class="py-2.5 pl-5 pr-3">
                                            <a href="{{ route('accounting.reports.ledger', ['account' => $account->id, 'period' => $period]) }}" wire:navigate class="flex items-baseline gap-3 decoration-zinc-300 underline-offset-2 hover:underline">
                                                <span class="w-10 shrink-0 font-data text-xs text-zinc-400 dark:text-zinc-500 group-hover:text-accent">{{ $account->account_code }}</span>
                                                <span class="text-zinc-800 dark:text-zinc-200">
                                                    {{ $account->account_name }}
                                                    @if ($account->is_contra)
                                                        <span class="ms-1 text-[11px] text-zinc-400 dark:text-zinc-500">({{ __('contra') }})</span>
                                                    @endif
                                                </span>
                                            </a>
                                        </td>
                                        <td class="{{ $num }} py-2.5 pr-5 {{ bccomp($line['amount'], '0', 2) < 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-800 dark:text-zinc-200' }}">{{ $this->amount($line['amount']) }}</td>
                                    </tr>
                                @empty
                                    <tr class="border-b border-zinc-200 dark:border-white/10">
                                        <td class="py-2.5 pl-5 pr-3">
                                            <span class="flex items-baseline gap-3">
                                                <span class="w-10 shrink-0"></span>
                                                <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ __('None') }}</span>
                                            </span>
                                        </td>
                                        <td class="{{ $num }} py-2.5 pr-5 text-zinc-400">0</td>
                                    </tr>
                                @endforelse
                                @isset($block['extra'])
                                    <tr class="border-b border-zinc-200 dark:border-white/10">
                                        <td class="py-2.5 pl-5 pr-3">
                                            <span class="flex items-baseline gap-3">
                                                <span class="w-10 shrink-0"></span>
                                                <span>
                                                    <span class="text-zinc-800 dark:text-zinc-200">{{ $block['extra'][0] }}</span>
                                                    <a href="{{ route('accounting.reports.income-statement', ['period' => $period]) }}" wire:navigate class="ms-1.5 text-[11px] text-accent hover:underline underline-offset-2">{{ __('see income statement') }}</a>
                                                </span>
                                            </span>
                                        </td>
                                        <td class="{{ $num }} py-2.5 pr-5 {{ bccomp($block['extra'][1], '0', 2) < 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-800 dark:text-zinc-200' }}">{{ $this->amount($block['extra'][1]) }}</td>
                                    </tr>
                                @endisset
                                @if ($block['total'])
                                    <tr class="bg-zinc-50/70 dark:bg-white/[0.02]">
                                        <td class="py-2.5 pl-5 pr-3">
                                            <span class="flex items-baseline gap-3">
                                                <span class="w-10 shrink-0"></span>
                                                <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ $block['total'][0] }}</span>
                                            </span>
                                        </td>
                                        <td class="{{ $num }} py-2.5 pr-5 font-semibold text-zinc-900 dark:text-white">{{ $this->amount($block['total'][1]) }}</td>
                                    </tr>
                                @endif
                            </tbody>
                        @endforeach
                    </table>
                    <div class="mt-auto flex items-center justify-between gap-3 border-t-2 border-zinc-300 dark:border-white/20 bg-zinc-50 dark:bg-white/[0.03] px-5 py-3.5">
                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $side['grand'][0] }}</span>
                        <span class="font-data tabular-nums text-sm font-semibold text-zinc-900 dark:text-white">{{ $this->amount($side['grand'][1]) }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border px-4 py-2.5 text-xs {{ $d['balanced'] ? 'border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-900 text-zinc-600 dark:text-zinc-300' : 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300' }}">
            <span class="inline-flex flex-wrap items-center gap-1.5">
                <flux:icon :name="$d['balanced'] ? 'check-circle' : 'exclamation-triangle'" variant="micro" class="size-4 {{ $d['balanced'] ? 'text-green-600 dark:text-green-400' : '' }}" />
                <span class="font-medium {{ $d['balanced'] ? 'text-zinc-900 dark:text-white' : '' }}">{{ $d['balanced'] ? __('Balanced') : __('Not balanced') }}</span>
                <span>· {{ __('Assets :assets = liabilities and equity :right', ['assets' => $this->rupiah($t['assets']), 'right' => $this->rupiah($t['liabilities_and_equity'])]) }}</span>
            </span>
            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Current earnings = all revenue minus all expenses so far; there is no year-end closing yet.') }}</span>
        </div>
    </div>
</section>
