<?php

use App\Actions\Accounting\ClosePpnPeriodAction;
use App\Exceptions\InvalidJournalEntryException;
use App\Exceptions\InvalidPpnEntryException;
use App\Models\JournalEntry;
use App\Support\PpnPeriod;
use App\Support\Reports\ReportPeriod;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('PPN Settlement')] class extends Component {
    #[Url]
    public string $period = '';

    public function mount(): void
    {
        Gate::authorize('ppn.manage');
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
    public function month(): PpnPeriod
    {
        return new PpnPeriod($this->period);
    }

    #[Computed]
    public function closing(): ?JournalEntry
    {
        return $this->month->closingEntry();
    }

    #[Computed]
    public function closingPlan(): array
    {
        return $this->month->closingPlan();
    }

    public function close(): void
    {
        Gate::authorize('ppn.manage');

        try {
            $entry = app(ClosePpnPeriodAction::class)->handle($this->month, Auth::id());
        } catch (InvalidPpnEntryException|InvalidJournalEntryException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        unset($this->closing, $this->closingPlan);
        $this->modal('close-period')->close();
        Flux::toast(variant: 'success', text: __(':period closed (:number).', ['period' => $this->month->label(), 'number' => $entry->journal_number]));
    }

    public function rupiah(string $value): string
    {
        return ((float) $value < 0 ? '−Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.');
    }
}; ?>

@php($month = $this->month)
@php($closed = $this->closing !== null)
@php($masukan = $month->masukan())
@php($keluaran = $month->keluaran())
@php($net = $month->net())
@php($kurangBayar = bccomp($net, '0', 2) > 0)
@php($canClose = ! $closed && $month->isFinished())

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('accounting.dashboard')" wire:navigate>{{ __('Accounting') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('PPN Settlement') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('PPN Settlement') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Nets PPN Masukan against PPN Keluaran for the month and posts the difference to Utang PPN.') }}</p>
        </div>
        @if ($canClose)
            <flux:modal.trigger name="close-period"><flux:button variant="filled" icon="lock-closed" class="active:scale-[0.95]">{{ __('Close :period', ['period' => $month->label()]) }}</flux:button></flux:modal.trigger>
        @endif
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ __('Period') }}</span>
        <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="shiftPeriod(-1)" :aria-label="__('Previous month')" class="active:scale-[0.95]" />
        <div class="w-44">
            <flux:input size="sm" type="month" wire:model.live="period" :aria-label="__('Period')" />
        </div>
        <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="shiftPeriod(1)" :aria-label="__('Next month')" class="active:scale-[0.95]" />
        @if ($closed)
            <a href="{{ route('accounting.journal.show', $this->closing) }}" wire:navigate class="inline-flex items-center gap-1 rounded-full border border-zinc-200 dark:border-white/10 bg-zinc-100 dark:bg-white/5 px-2.5 py-0.5 text-xs font-medium text-zinc-600 dark:text-zinc-300 hover:underline">
                <flux:icon.lock-closed variant="micro" class="size-3.5" />{{ __('Closed — :number', ['number' => $this->closing->journal_number]) }}
            </a>
        @elseif ($month->isFuture())
            <span class="rounded-full border border-zinc-200 dark:border-white/10 px-2.5 py-0.5 text-xs text-zinc-500">{{ __('Future month') }}</span>
        @else
            <span class="rounded-full border border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 px-2.5 py-0.5 text-xs font-medium text-green-700 dark:text-green-400">{{ __('Open period') }}</span>
            @if (! $month->isFinished())
                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Can be closed from :date.', ['date' => $month->end->copy()->addDay()->translatedFormat('j M Y')]) }}</span>
            @endif
        @endif
    </div>

    <div wire:loading.class="opacity-50" wire:target="period,shiftPeriod" class="transition-opacity">
        <div class="mt-6 rounded-xl border border-zinc-200 dark:border-white/10 bg-gradient-to-br from-accent/[0.07] to-transparent dark:from-accent/10 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-6">
                <div>
                    <h2 class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ $kurangBayar ? __('Kurang bayar (owed)') : __('Lebih bayar (credit carried forward)') }}</h2>
                    <p class="mt-1.5 font-display text-4xl font-semibold tracking-tight {{ $kurangBayar ? 'text-red-700 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">{{ $this->rupiah(abs((float) $net)) }}</p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $closed ? __('posted to Utang PPN at closing') : __('will post to Utang PPN at closing') }}</p>
                </div>
                <div class="flex gap-6 text-right">
                    <div>
                        <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('PPN Keluaran') }}</p>
                        <p class="mt-1 font-display text-lg font-semibold text-zinc-900 dark:text-white">{{ $this->rupiah($keluaran) }}</p>
                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('from sales invoices') }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('PPN Masukan') }}</p>
                        <p class="mt-1 font-display text-lg font-semibold text-zinc-900 dark:text-white">{{ $this->rupiah($masukan) }}</p>
                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('from purchase invoices') }}</p>
                    </div>
                </div>
            </div>
        </div>

        <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
            @if ($kurangBayar)
                {{ __('PPN Keluaran collected from customers this month is more than PPN Masukan paid to suppliers — the difference is owed to the tax office.') }}
            @elseif (bccomp($net, '0', 2) < 0)
                {{ __('PPN Masukan paid to suppliers this month is more than PPN Keluaran collected from customers — the difference is a credit carried into next month.') }}
            @else
                {{ __('PPN Keluaran and PPN Masukan exactly offset this month — nothing owed, nothing carried forward.') }}
            @endif
        </p>
    </div>

    <flux:modal name="close-period" class="max-w-xl">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Close :period?', ['period' => $month->label()]) }}</flux:heading>
                <flux:subheading>{{ __('This closing entry is posted on :date. After closing, PPN Masukan and PPN Keluaran for this month can no longer change.', ['date' => $month->end->translatedFormat('j M Y')]) }}</flux:subheading>
            </div>
            @php($closePlan = $this->closingPlan)
            @php($closeUnmapped = collect(\App\Support\JournalPreview::resolve($closePlan))->where('unmapped', true))
            @if ($closePlan === [])
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('There is no PPN Masukan or PPN Keluaran in :period, so there is nothing to close.', ['period' => $month->label()]) }}</p>
            @else
                <x-journal-confirm :plan="$closePlan" />
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="close" wire:loading.attr="disabled" wire:target="close" :disabled="$closePlan === [] || $closeUnmapped->isNotEmpty()">{{ __('Close period') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
