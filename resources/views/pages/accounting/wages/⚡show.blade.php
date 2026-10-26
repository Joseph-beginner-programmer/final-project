<?php

use App\Actions\Accounting\CancelWagePaymentAction;
use App\Enums\DocumentStatus;
use App\Exceptions\InvalidJournalEntryException;
use App\Exceptions\InvalidWagePaymentException;
use App\Models\JournalEntry;
use App\Models\ProductionResultLabor;
use App\Models\WagePayment;
use App\Support\JournalPreview;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Wage Payment')] class extends Component {
    public WagePayment $wagePayment;

    public function mount(WagePayment $wagePayment): void
    {
        Gate::authorize('wages.pay');
        $this->wagePayment = $wagePayment;
    }

    #[Computed]
    public function payment(): WagePayment
    {
        return $this->wagePayment->load(['lines.employee', 'cashAccount', 'createdBy', 'cancelledBy']);
    }

    #[Computed]
    public function work(): Collection
    {
        return ProductionResultLabor::query()
            ->where('wage_payment_id', $this->wagePayment->id)
            ->with('productionResult.workOrder.product')
            ->get()
            ->groupBy('employee_id');
    }

    #[Computed]
    public function journals(): Collection
    {
        return JournalEntry::where('source_type', 'wage_payment')->where('source_id', $this->wagePayment->id)->orderBy('id')->get();
    }

    public function reversalPlan(): array
    {
        return JournalPreview::reversalOf($this->journals->whereNull('reversal_of_id')->where('status.value', 'posted')->first());
    }

    public function cancel(): void
    {
        Gate::authorize('wages.pay');

        try {
            app(CancelWagePaymentAction::class)->handle($this->wagePayment, Auth::id());
        } catch (InvalidWagePaymentException|InvalidJournalEntryException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->wagePayment = $this->wagePayment->fresh();
        unset($this->payment, $this->work, $this->journals);
        $this->modal('cancel-wages')->close();
        Flux::toast(variant: 'success', text: __('Wage payment cancelled. The hours are unpaid again.'));
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

@php($payment = $this->payment)
@php($cancelled = $payment->status === DocumentStatus::Cancelled)
@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')

<section class="w-full max-w-5xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('accounting.wages.index')" wire:navigate>{{ __('Wage Payments') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $payment->payment_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-display font-data text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $payment->payment_number }}</h1>
                @if ($cancelled)
                    <span class="inline-flex items-center rounded-full border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/5 px-2.5 py-0.5 text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ __('Cancelled') }}</span>
                @else
                    <span class="inline-flex items-center rounded-full border border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 px-2.5 py-0.5 text-xs font-medium text-green-700 dark:text-green-400">{{ __('Paid') }}</span>
                @endif
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('Work up to :date', ['date' => $payment->period_end->translatedFormat('d M Y')]) }} · {{ __('by :name', ['name' => $payment->createdBy->name]) }}
                @if ($cancelled) · {{ __('cancelled by :name on :date', ['name' => $payment->cancelledBy?->name, 'date' => $payment->cancelled_at?->translatedFormat('d M Y')]) }}@endif
            </p>
        </div>
        @if (! $cancelled)
            <flux:modal.trigger name="cancel-wages"><flux:button size="sm" variant="ghost" icon="x-mark" class="text-zinc-500 hover:text-red-600 active:scale-[0.95]">{{ __('Cancel payment') }}</flux:button></flux:modal.trigger>
        @endif
    </div>

    <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ([
            [__('Amount paid'), $this->rupiah($payment->total_amount)],
            [__('Employees'), $payment->lines->count()],
            [__('Hours'), $this->hours($payment->lines->sum(fn ($line) => (float) $line->hours))],
            [__('Paid from'), $payment->cashAccount->account_name.' · '.$payment->payment_date->translatedFormat('d M Y')],
        ] as [$tileLabel, $value])
            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $tileLabel }}</p>
                <p class="mt-2 text-lg font-semibold text-zinc-900 dark:text-white">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 min-w-0 overflow-hidden rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="{{ $th }}">{{ __('Employee') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Hours') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Wages') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payment->lines->sortBy(fn ($line) => $line->employee->name) as $line)
                        <tr class="border-b border-zinc-100 dark:border-white/5 last:border-0 align-top">
                            <td class="py-2.5 px-3">
                                <p class="font-medium text-zinc-900 dark:text-white">{{ $line->employee->name }}</p>
                                @if (! $cancelled && $this->work->has($line->employee_id))
                                    <div class="mt-1 flex flex-wrap gap-1.5">
                                        @foreach ($this->work[$line->employee_id]->sortBy(fn ($labor) => $labor->productionResult->production_date) as $labor)
                                            <a href="{{ route('production.results.show', $labor->productionResult) }}" wire:navigate class="inline-flex items-center gap-1 rounded border border-zinc-200 dark:border-white/10 px-1.5 py-0.5 text-[11px] text-zinc-600 dark:text-zinc-300 hover:border-accent hover:text-accent" title="{{ $labor->productionResult->workOrder->product->product_name }}">
                                                <span class="font-data">{{ $labor->productionResult->result_number }}</span>
                                                <span class="text-zinc-400">{{ $labor->productionResult->production_date->translatedFormat('d/m') }} · {{ $this->hours($labor->hours) }}j</span>
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->hours($line->hours) }}</td>
                            <td class="py-2.5 px-3 text-right font-data tabular-nums font-medium text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->rupiah($line->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-zinc-200 dark:border-white/20 bg-zinc-50 dark:bg-white/5 font-semibold">
                        <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ __('Total') }}</td>
                        <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white">{{ $this->hours($payment->lines->sum(fn ($line) => (float) $line->hours)) }}</td>
                        <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->rupiah($payment->total_amount) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="space-y-6">
            @if ($payment->reference)
                <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Reference') }}</p>
                    <p class="mt-1 text-sm text-zinc-900 dark:text-white">{{ $payment->reference }}</p>
                </div>
            @endif
            @if ($this->journals->isNotEmpty() && Gate::allows('journal.manage'))
                <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Journal entries') }}</h2>
                    <div class="mt-2 space-y-1.5">
                        @foreach ($this->journals as $journal)
                            <a href="{{ route('accounting.journal.show', $journal) }}" wire:navigate class="flex items-center justify-between gap-3 text-xs hover:underline underline-offset-2">
                                <span class="font-data text-accent">{{ $journal->journal_number }}</span>
                                <span class="text-zinc-500 dark:text-zinc-400 {{ $journal->status->value === 'reversed' ? 'line-through' : '' }}">{{ $journal->reversal_of_id ? __('Reversal') : __('Payment') }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if (! $cancelled)
        <flux:modal name="cancel-wages" class="max-w-xl">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Cancel this wage payment?') }}</flux:heading>
                    <flux:subheading>{{ __('A reversing journal entry is posted and these hours become unpaid again, so they can be paid in a new payment.') }}</flux:subheading>
                </div>
                <x-journal-confirm :plan="$this->reversalPlan()" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel">{{ __('Cancel payment') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</section>
