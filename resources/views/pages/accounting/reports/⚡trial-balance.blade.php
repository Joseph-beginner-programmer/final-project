<?php

use App\Support\Reports\ReportPeriod;
use App\Support\Reports\TrialBalanceReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Trial Balance')] class extends Component {
    #[Url]
    public string $period = '';

    #[Url]
    public bool $all = false;

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
    public function rows(): Collection
    {
        return (new TrialBalanceReport(ReportPeriod::start($this->period), ReportPeriod::end($this->period)))->rows($this->all);
    }

    #[Computed]
    public function totals(): array
    {
        return TrialBalanceReport::totals($this->rows);
    }

    public function amount(string $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    public function rupiah(string $value): string
    {
        return 'Rp '.number_format((float) $value, 0, ',', '.');
    }
}; ?>

@php($t = $this->totals)
@php($balanced = TrialBalanceReport::isBalanced($t))
@php($hasOpening = bccomp($t['opening_debit'], '0', 2) !== 0 || bccomp($t['opening_credit'], '0', 2) !== 0)
@php($groups = $this->rows->groupBy(fn ($row) => $row['account']->type->value))
@php($exportParams = ['period' => $period, 'all' => $all ? 1 : null])
@php($th = 'px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 whitespace-nowrap')
@php($num = 'w-32 px-3 text-right font-data tabular-nums whitespace-nowrap')
@php($divider = 'border-l border-zinc-300 dark:border-white/20')
@php($closingBg = 'bg-zinc-50 dark:bg-white/[0.03]')
@php($cols = $hasOpening ? 7 : 5)

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route(auth()->user()->role->reportsRoute())" wire:navigate>{{ __('Reports') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Trial Balance') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Trial Balance') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Every account\'s opening balance, movements and closing balance for the month. Debit and credit must be equal in every column.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="filled" icon="document-arrow-down" :href="route('accounting.reports.trial-balance.export', ['format' => 'pdf', ...$exportParams])" class="active:scale-[0.95]">PDF</flux:button>
            <flux:button size="sm" variant="filled" icon="table-cells" :href="route('accounting.reports.trial-balance.export', ['format' => 'csv', ...$exportParams])" class="active:scale-[0.95]">CSV</flux:button>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center justify-between gap-x-5 gap-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400 me-1">{{ __('Period') }}</span>
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="shiftPeriod(-1)" :aria-label="__('Previous month')" class="active:scale-[0.95]" />
            <div class="w-44">
                <flux:input size="sm" type="month" wire:model.live="period" :aria-label="__('Period')" />
            </div>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="shiftPeriod(1)" :aria-label="__('Next month')" class="active:scale-[0.95]" />
        </div>
        <flux:switch wire:model.live="all" :label="__('Show accounts without balance')" align="left" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="period,all,shiftPeriod" class="mt-5 transition-opacity">
        @if (! $hasOpening && $this->rows->isNotEmpty())
            <p class="mb-2 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('No opening balances: this is the first month with journal entries, so the opening columns are hidden.') }}</p>
        @endif

        <div class="overflow-x-auto rounded-xl border border-zinc-300 dark:border-white/20 bg-white dark:bg-zinc-900">
            <table class="w-full min-w-[44rem] text-[13px] border-collapse">
                <thead>
                    <tr class="border-b border-zinc-300 dark:border-white/20">
                        <th rowspan="2" class="{{ $th }} py-2 text-left align-bottom">{{ __('Account') }}</th>
                        @if ($hasOpening)
                            <th colspan="2" class="{{ $th }} {{ $divider }} pt-2.5 pb-1 text-center">{{ __('Opening balance') }}</th>
                        @endif
                        <th colspan="2" class="{{ $th }} {{ $divider }} pt-2.5 pb-1 text-center">{{ __('Movements') }}</th>
                        <th colspan="2" class="{{ $th }} {{ $divider }} {{ $closingBg }} pt-2.5 pb-1 text-center !text-zinc-800 dark:!text-zinc-100">{{ __('Closing balance') }}</th>
                    </tr>
                    <tr class="border-b border-zinc-300 dark:border-white/20">
                        @if ($hasOpening)
                            <th class="{{ $th }} {{ $divider }} pb-2 text-center font-medium">{{ __('Debit') }}</th>
                            <th class="{{ $th }} pb-2 text-center font-medium">{{ __('Credit') }}</th>
                        @endif
                        <th class="{{ $th }} {{ $divider }} pb-2 text-center font-medium">{{ __('Debit') }}</th>
                        <th class="{{ $th }} pb-2 text-center font-medium">{{ __('Credit') }}</th>
                        <th class="{{ $th }} {{ $divider }} {{ $closingBg }} pb-2 text-center font-medium">{{ __('Debit') }}</th>
                        <th class="{{ $th }} {{ $closingBg }} pb-2 text-center font-medium">{{ __('Credit') }}</th>
                    </tr>
                </thead>
                @forelse ($groups as $type => $rows)
                    @php($group = TrialBalanceReport::totals($rows))
                    <tbody>
                        <tr class="border-b border-zinc-300 dark:border-white/20">
                            <td class="px-3 pt-4 pb-1.5 text-xs font-semibold text-zinc-900 dark:text-white">
                                {{ $rows->first()['account']->type->label() }}
                                <span class="ms-1 font-normal text-zinc-400 dark:text-zinc-500">{{ $rows->count() }}</span>
                            </td>
                            @if ($hasOpening)
                                <td class="{{ $divider }}"></td><td></td>
                            @endif
                            <td class="{{ $divider }}"></td><td></td>
                            <td class="{{ $num }} {{ $divider }} {{ $closingBg }} pt-4 pb-1.5 text-xs font-semibold text-zinc-900 dark:text-white">{{ $this->amount($group['closing_debit']) }}</td>
                            <td class="{{ $num }} {{ $closingBg }} pt-4 pb-1.5 text-xs font-semibold text-zinc-900 dark:text-white">{{ $this->amount($group['closing_credit']) }}</td>
                        </tr>
                        @foreach ($rows as $row)
                            @php($account = $row['account'])
                            <tr wire:key="tb-{{ $account->id }}" class="group border-b border-zinc-200 dark:border-white/10 hover:bg-accent/[0.04]">
                                <td class="px-3 py-2">
                                    <a href="{{ route('accounting.reports.ledger', ['account' => $account->id, 'period' => $period]) }}" wire:navigate class="inline-flex items-baseline gap-2.5 hover:underline underline-offset-2 decoration-zinc-300">
                                        <span class="w-10 shrink-0 font-data text-xs text-zinc-400 dark:text-zinc-500 group-hover:text-accent">{{ $account->account_code }}</span>
                                        <span class="text-zinc-800 dark:text-zinc-200">{{ $account->account_name }}</span>
                                    </a>
                                    @if ($account->is_contra)
                                        <span class="ms-1 text-[11px] text-zinc-400 dark:text-zinc-500">({{ __('contra') }})</span>
                                    @endif
                                </td>
                                @if ($hasOpening)
                                    <td class="{{ $num }} {{ $divider }} py-2 text-zinc-400 dark:text-zinc-500">{{ $this->amount($row['opening_debit']) }}</td>
                                    <td class="{{ $num }} py-2 text-zinc-400 dark:text-zinc-500">{{ $this->amount($row['opening_credit']) }}</td>
                                @endif
                                <td class="{{ $num }} {{ $divider }} py-2 text-zinc-500 dark:text-zinc-400">{{ $this->amount($row['debit']) }}</td>
                                <td class="{{ $num }} py-2 text-zinc-500 dark:text-zinc-400">{{ $this->amount($row['credit']) }}</td>
                                <td class="{{ $num }} {{ $divider }} {{ $closingBg }} py-2 font-medium text-zinc-900 dark:text-white">{{ $this->amount($row['closing_debit']) }}</td>
                                <td class="{{ $num }} {{ $closingBg }} py-2 font-medium text-zinc-900 dark:text-white">{{ $this->amount($row['closing_credit']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @empty
                    <tbody><tr><td colspan="{{ $cols }}" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No journal entries up to this period.') }}</td></tr></tbody>
                @endforelse
                <tfoot>
                    <tr class="border-t-2 border-zinc-300 dark:border-white/20">
                        <td class="px-3 py-3 text-xs font-semibold uppercase tracking-wider text-zinc-900 dark:text-white">{{ __('Total') }}</td>
                        @if ($hasOpening)
                            <td class="{{ $num }} {{ $divider }} py-3 font-semibold text-zinc-700 dark:text-zinc-300">{{ number_format((float) $t['opening_debit'], 0, ',', '.') }}</td>
                            <td class="{{ $num }} py-3 font-semibold text-zinc-700 dark:text-zinc-300">{{ number_format((float) $t['opening_credit'], 0, ',', '.') }}</td>
                        @endif
                        <td class="{{ $num }} {{ $divider }} py-3 font-semibold text-zinc-700 dark:text-zinc-300">{{ number_format((float) $t['debit'], 0, ',', '.') }}</td>
                        <td class="{{ $num }} py-3 font-semibold text-zinc-700 dark:text-zinc-300">{{ number_format((float) $t['credit'], 0, ',', '.') }}</td>
                        <td class="{{ $num }} {{ $divider }} {{ $closingBg }} py-3 font-semibold text-zinc-900 dark:text-white">{{ number_format((float) $t['closing_debit'], 0, ',', '.') }}</td>
                        <td class="{{ $num }} {{ $closingBg }} py-3 font-semibold text-zinc-900 dark:text-white">{{ number_format((float) $t['closing_credit'], 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border px-4 py-2.5 text-xs {{ $balanced ? 'border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-900 text-zinc-600 dark:text-zinc-300' : 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300' }}">
            <span class="inline-flex flex-wrap items-center gap-1.5">
                <flux:icon :name="$balanced ? 'check-circle' : 'exclamation-triangle'" variant="micro" class="size-4 {{ $balanced ? 'text-green-600 dark:text-green-400' : '' }}" />
                <span class="font-medium {{ $balanced ? 'text-zinc-900 dark:text-white' : '' }}">{{ $balanced ? __('Balanced') : __('Not balanced') }}</span>
                <span>· {{ __('Closing: debit :debit, credit :credit', ['debit' => $this->rupiah($t['closing_debit']), 'credit' => $this->rupiah($t['closing_credit'])]) }}</span>
            </span>
            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Click an account to open its general ledger.') }}</span>
        </div>
    </div>
</section>
