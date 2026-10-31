<?php

use App\Support\Reports\CashFlowReport;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Cash Flow Statement')] class extends Component {
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
        return (new CashFlowReport(ReportPeriod::start($this->period), ReportPeriod::end($this->period)))->build();
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
@php($num = 'pl-4 text-right font-data tabular-nums whitespace-nowrap')

<section class="w-full max-w-4xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route(auth()->user()->role->reportsRoute())" wire:navigate>{{ __('Reports') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Cash Flow Statement') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Cash Flow Statement') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Why cash went up or down this month, split into operating and financing activity.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="filled" icon="document-arrow-down" :href="route('accounting.reports.cash-flow.export', ['format' => 'pdf', 'period' => $period])" class="active:scale-[0.95]">PDF</flux:button>
            <flux:button size="sm" variant="filled" icon="table-cells" :href="route('accounting.reports.cash-flow.export', ['format' => 'excel', 'period' => $period])" class="active:scale-[0.95]">Excel</flux:button>
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

    @if ($d['cash_accounts']->isEmpty())
        <div class="mt-5 rounded-lg border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 px-4 py-3 text-xs text-amber-800 dark:text-amber-300">
            {{ __('No account is marked as a cash account yet. Mark Kas and/or Bank on the Chart of Accounts page first.') }}
        </div>
    @else
        <div wire:loading.class="opacity-50" wire:target="period,shiftPeriod" class="mt-5 transition-opacity">
            <div class="rounded-xl border border-zinc-300 dark:border-white/20 bg-white dark:bg-zinc-900 overflow-hidden">
                <table class="w-full text-[13px] border-collapse">
                    <tbody>
                        <tr class="border-b border-zinc-200 dark:border-white/10 bg-zinc-50/70 dark:bg-white/[0.02]">
                            <td class="py-2.5 pl-5 pr-3 font-semibold text-zinc-800 dark:text-zinc-200">{{ __('Opening cash') }}</td>
                            <td class="{{ $num }} py-2.5 pr-5 font-semibold text-zinc-900 dark:text-white">{{ $this->amount($d['opening_cash']) }}</td>
                        </tr>

                        @foreach ([
                            ['key' => 'operating', 'heading' => __('Operating activities')],
                            ['key' => 'financing', 'heading' => __('Financing activities')],
                        ] as $section)
                            <tr><td colspan="2" class="px-5 pt-5 pb-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $section['heading'] }}</td></tr>
                            @forelse ($d[$section['key']] as $line)
                                @php($account = $line['account'])
                                <tr wire:key="cf-{{ $section['key'] }}-{{ $account->id }}" class="border-b border-zinc-100 dark:border-white/5 hover:bg-accent/[0.04]">
                                    <td class="py-2 pl-5 pr-3">
                                        <a href="{{ route('accounting.reports.ledger', ['account' => $account->id, 'period' => $period]) }}" wire:navigate class="flex items-baseline gap-3 decoration-zinc-300 underline-offset-2 hover:underline">
                                            <span class="w-10 shrink-0 font-data text-xs text-zinc-400 dark:text-zinc-500">{{ $account->account_code }}</span>
                                            <span class="text-zinc-700 dark:text-zinc-300">{{ $account->account_name }}</span>
                                        </a>
                                    </td>
                                    <td class="{{ $num }} py-2 pr-5 {{ bccomp($line['amount'], '0', 2) < 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-800 dark:text-zinc-200' }}">{{ $this->amount($line['amount']) }}</td>
                                </tr>
                            @empty
                                <tr class="border-b border-zinc-100 dark:border-white/5">
                                    <td class="py-2 pl-5 pr-3">
                                        <span class="flex items-baseline gap-3">
                                            <span class="w-10 shrink-0"></span>
                                            <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ __('None') }}</span>
                                        </span>
                                    </td>
                                    <td class="{{ $num }} py-2 pr-5 text-zinc-400">0</td>
                                </tr>
                            @endforelse
                            <tr class="border-b border-zinc-200 dark:border-white/10 bg-zinc-50/50 dark:bg-white/[0.015]">
                                <td class="py-2 pl-5 pr-3">
                                    <span class="flex items-baseline gap-3">
                                        <span class="w-10 shrink-0"></span>
                                        <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Net cash from :activity', ['activity' => strtolower($section['heading'])]) }}</span>
                                    </span>
                                </td>
                                <td class="{{ $num }} py-2 pr-5 font-semibold text-zinc-900 dark:text-white">{{ $this->amount($d['totals'][$section['key']]) }}</td>
                            </tr>
                        @endforeach

                        <tr class="border-t-2 border-zinc-300 dark:border-white/20 bg-zinc-50 dark:bg-white/[0.03]">
                            <td class="py-2.5 pl-5 pr-3 font-semibold text-zinc-900 dark:text-white">{{ __('Net change in cash') }}</td>
                            <td class="{{ $num }} py-2.5 pr-5 font-semibold text-zinc-900 dark:text-white">{{ $this->amount($d['net_change']) }}</td>
                        </tr>
                        <tr class="bg-zinc-50 dark:bg-white/[0.03]">
                            <td class="py-2.5 pl-5 pr-3 font-semibold text-zinc-900 dark:text-white">{{ __('Closing cash') }}</td>
                            <td class="{{ $num }} py-2.5 pr-5 font-semibold text-zinc-900 dark:text-white">{{ $this->amount($d['closing_cash']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border px-4 py-2.5 text-xs {{ $d['reconciled'] ? 'border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-900 text-zinc-600 dark:text-zinc-300' : 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300' }}">
                <span class="inline-flex flex-wrap items-center gap-1.5">
                    <flux:icon :name="$d['reconciled'] ? 'check-circle' : 'exclamation-triangle'" variant="micro" class="size-4 {{ $d['reconciled'] ? 'text-green-600 dark:text-green-400' : '' }}" />
                    <span class="font-medium {{ $d['reconciled'] ? 'text-zinc-900 dark:text-white' : '' }}">{{ $d['reconciled'] ? __('Reconciled') : __('Not reconciled') }}</span>
                    <span>· {{ __('Opening :opening + net change :change = closing :closing', ['opening' => $this->rupiah($d['opening_cash']), 'change' => $this->rupiah($d['net_change']), 'closing' => $this->rupiah($d['closing_cash'])]) }}</span>
                </span>
                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Cash accounts') }}: {{ $d['cash_accounts']->pluck('account_name')->implode(', ') }}</span>
            </div>
        </div>
    @endif
</section>
