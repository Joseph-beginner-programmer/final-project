<?php

use App\Enums\AccountSystemRole;
use App\Enums\DocumentStatus;
use App\Models\Account;
use App\Models\WagePayment;
use App\Support\Reports\AccountBalances;
use App\Support\UnpaidWages;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Wage Payments')] class extends Component {
    public function mount(): void
    {
        Gate::authorize('wages.pay');
    }

    #[Computed]
    public function unpaid(): Collection
    {
        return UnpaidWages::perEmployee();
    }

    #[Computed]
    public function wagesPayable(): ?string
    {
        $account = Account::where('system_role', AccountSystemRole::WagesPayable->value)->first();
        if (! $account) {
            return null;
        }

        return bcmul(AccountBalances::net(null, now())[$account->id] ?? '0', '-1', 2);
    }

    #[Computed]
    public function payments(): Collection
    {
        return WagePayment::query()
            ->with('cashAccount')
            ->withCount('lines')
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();
    }

    public function rupiah(string|float $value): string
    {
        return ((float) $value < 0 ? '−Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.');
    }

    public function hours(string|float $value): string
    {
        return number_format((float) $value, (float) $value == floor((float) $value) ? 0 : 1, ',', '.');
    }
}; ?>

@php($total = $this->unpaid->reduce(fn (string $carry, array $row) => bcadd($carry, $row['amount'], 2), '0'))
@php($maxAmount = max(1, (float) ($this->unpaid->max(fn ($row) => (float) $row['amount']) ?? 1)))
@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('accounting.dashboard')" wire:navigate>{{ __('Accounting') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Wage Payments') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Wage Payments') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Pay production workers for the hours recorded in posted production results.') }}</p>
        </div>
        @if ($this->unpaid->isNotEmpty())
            <flux:button size="sm" variant="primary" icon="banknotes" :href="route('accounting.wages.pay')" wire:navigate class="active:scale-[0.95]">{{ __('Pay wages') }}</flux:button>
        @endif
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 min-w-0 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Not yet paid') }}</h2>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Earned in posted production results, per employee.') }}</p>
                </div>
                <p class="font-data text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->rupiah($total) }}</p>
            </div>
            <div class="mt-4 space-y-3">
                @forelse ($this->unpaid as $row)
                    <div wire:key="unpaid-{{ $row['employee']->id }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 text-xs">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $row['employee']->name }}</span>
                            <span class="font-data tabular-nums font-semibold text-zinc-900 dark:text-white">{{ $this->rupiah($row['amount']) }}</span>
                        </div>
                        <div class="mt-1.5 h-1.5 rounded-full bg-zinc-200 dark:bg-white/10">
                            <div class="h-full rounded-full bg-accent" style="width: {{ (float) $row['amount'] / $maxAmount * 100 }}%"></div>
                        </div>
                        <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">
                            {{ __(':hours hours in :count results', ['hours' => $this->hours($row['hours']), 'count' => $row['results']]) }} ·
                            {{ $row['from']->isSameDay($row['to']) ? $row['from']->translatedFormat('d M Y') : $row['from']->translatedFormat('d M').' – '.$row['to']->translatedFormat('d M Y') }}
                        </p>
                    </div>
                @empty
                    <div class="flex items-center justify-center gap-2 py-10 text-sm text-zinc-500 dark:text-zinc-400">
                        <flux:icon.check-circle variant="micro" class="size-4 text-green-600 dark:text-green-400" />{{ __('Every recorded hour has been paid.') }}
                    </div>
                @endforelse
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Wages payable on the books') }}</h2>
            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Balance of the Wages Payable account today.') }}</p>
            @if ($this->wagesPayable === null)
                <p class="mt-4 text-xs text-amber-700 dark:text-amber-400">{{ __('Map the Wages Payable system role on the Chart of Accounts page first.') }}</p>
            @else
                <p class="mt-3 font-data text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->rupiah($this->wagesPayable) }}</p>
                @if (bccomp($this->wagesPayable, $total, 2) === 0)
                    <p class="mt-2 flex items-center gap-1.5 text-[11px] text-green-700 dark:text-green-400"><flux:icon.check-circle variant="micro" class="size-3.5" />{{ __('Matches the unpaid hours.') }}</p>
                @else
                    <p class="mt-2 flex items-start gap-1.5 text-[11px] text-amber-700 dark:text-amber-400"><flux:icon.exclamation-triangle variant="micro" class="mt-px size-3.5 shrink-0" />{{ __('Differs from the unpaid hours by :amount: a manual journal on Wages Payable is not linked to production results.', ['amount' => $this->rupiah(bcsub($this->wagesPayable, $total, 2))]) }}</p>
                @endif
            @endif
            <p class="mt-4 border-t border-zinc-100 dark:border-white/5 pt-3 text-[11px] leading-relaxed text-zinc-500 dark:text-zinc-400">{{ __('Labor is credited to Wages Payable when a production result is posted. Paying clears it against a cash or bank account.') }}</p>
        </div>
    </div>

    <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Payment history') }}</h2>
    <div class="mt-3 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
        <table class="w-full min-w-[44rem] text-xs border-collapse">
            <thead>
                <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                    <th class="{{ $th }}">{{ __('Number') }}</th>
                    <th class="{{ $th }}">{{ __('Paid on') }}</th>
                    <th class="{{ $th }}">{{ __('Work up to') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Employees') }}</th>
                    <th class="{{ $th }}">{{ __('Paid from') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Amount') }}</th>
                    <th class="{{ $th }}">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->payments as $payment)
                    @php($cancelled = $payment->status === DocumentStatus::Cancelled)
                    <tr wire:key="wp-{{ $payment->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50 dark:hover:bg-white/[0.03] {{ $cancelled ? 'opacity-60' : '' }}">
                        <td class="py-2.5 px-3"><a href="{{ route('accounting.wages.show', $payment) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2 {{ $cancelled ? 'line-through' : '' }}">{{ $payment->payment_number }}</a></td>
                        <td class="py-2.5 px-3 text-zinc-700 dark:text-zinc-300">{{ $payment->payment_date->translatedFormat('d M Y') }}</td>
                        <td class="py-2.5 px-3 text-zinc-700 dark:text-zinc-300">{{ $payment->period_end->translatedFormat('d M Y') }}</td>
                        <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $payment->lines_count }}</td>
                        <td class="py-2.5 px-3 text-zinc-700 dark:text-zinc-300">{{ $payment->cashAccount->account_name }}</td>
                        <td class="py-2.5 px-3 text-right font-data tabular-nums font-medium text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->rupiah($payment->total_amount) }}</td>
                        <td class="py-2.5 px-3">
                            @if ($cancelled)
                                <span class="inline-flex items-center rounded-full border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/5 px-2 py-0.5 text-[11px] text-zinc-600 dark:text-zinc-400">{{ __('Cancelled') }}</span>
                            @else
                                <span class="inline-flex items-center rounded-full border border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 px-2 py-0.5 text-[11px] text-green-700 dark:text-green-400">{{ __('Paid') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No wage payments yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
