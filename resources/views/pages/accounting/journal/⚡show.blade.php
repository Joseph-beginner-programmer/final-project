<?php

use App\Actions\Accounting\ReverseJournalEntryAction;
use App\Enums\JournalStatus;
use App\Models\JournalEntry;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Journal Entry')] class extends Component {
    public JournalEntry $journalEntry;

    public function mount(JournalEntry $journalEntry): void
    {
        Gate::authorize('viewAny', JournalEntry::class);

        $this->journalEntry = $journalEntry->load(['lines.account', 'createdBy', 'reversalOf']);
    }

    public function reverse(): void
    {
        Gate::authorize('reverse', $this->journalEntry);

        $reversal = app(ReverseJournalEntryAction::class)->handle($this->journalEntry, Auth::id());

        Flux::toast(variant: 'success', text: __('Reversed as :number.', ['number' => $reversal->journal_number]));
        $this->redirectRoute('accounting.journal.show', $reversal, navigate: true);
    }

    public function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    #[Computed]
    public function reversedBy(): ?JournalEntry
    {
        return JournalEntry::where('reversal_of_id', $this->journalEntry->id)->first();
    }

    public function sourceLabel(): ?string
    {
        if (! $this->journalEntry->source_type) {
            return null;
        }

        return match ($this->journalEntry->source_type) {
            'purchase_order_receipt' => __('Purchase Order Receipt'),
            'material_issue' => __('Material Issue'),
            'production_result' => __('Production Result'),
            'production_receipt' => __('Production Receipt'),
            'material_return' => __('Material Return'),
            'sales_delivery' => __('Sales Delivery'),
            default => $this->journalEntry->source_type,
        };
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

<section class="w-full max-w-4xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('accounting.dashboard')" wire:navigate>{{ __('Accounting') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('accounting.journal.list')" wire:navigate>{{ __('General Journal') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $journalEntry->journal_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-data text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $journalEntry->journal_number }}</h1>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-medium {{ $journalEntry->status === JournalStatus::Posted ? 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20' : 'bg-zinc-100 text-zinc-500 border-zinc-200 dark:bg-white/5 dark:text-zinc-500 dark:border-white/10' }}">{{ $journalEntry->status->label() }}</span>
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1.5">{{ $journalEntry->description }}</p>
        </div>

        @can('reverse', $journalEntry)
            @if ($journalEntry->status === JournalStatus::Posted)
                <flux:button variant="filled" icon="arrow-uturn-left" wire:click="reverse" wire:confirm="{{ __('Reverse this journal entry? A new entry with every line swapped will be posted.') }}" wire:loading.attr="disabled" wire:target="reverse" class="active:scale-[0.95]">
                    {{ __('Reverse') }}
                </flux:button>
            @endif
        @endcan
    </div>

    <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-4 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">{{ __('Date') }}</p>
            <p class="font-data tabular-nums text-sm text-zinc-800 dark:text-zinc-200">{{ $journalEntry->entry_date->format('d M Y') }}</p>
        </div>
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-4 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">{{ __('Type') }}</p>
            <p class="text-sm text-zinc-800 dark:text-zinc-200">{{ $journalEntry->entry_type->label() }}</p>
        </div>
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-4 motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">{{ __('Created By') }}</p>
            <p class="text-sm text-zinc-800 dark:text-zinc-200">{{ $journalEntry->createdBy->name }}</p>
        </div>
    </div>

    @if ($this->sourceLabel())
        <div class="mt-4 rounded-md border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/5 px-4 py-2.5 text-xs text-zinc-600 dark:text-zinc-300 motion-safe:animate-fade-slide-up" style="animation-delay: 110ms;">
            {{ __('Generated automatically from') }} <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $this->sourceLabel() }}</span>
        </div>
    @endif

    @if ($journalEntry->reversalOf)
        <div class="mt-4 rounded-md border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 px-4 py-2.5 text-xs text-amber-800 dark:text-amber-300 motion-safe:animate-fade-slide-up" style="animation-delay: 110ms;">
            {{ __('This entry reverses') }}
            <a href="{{ route('accounting.journal.show', $journalEntry->reversalOf) }}" wire:navigate class="font-medium underline underline-offset-2">{{ $journalEntry->reversalOf->journal_number }}</a>
        </div>
    @endif

    @if ($this->reversedBy)
        <div class="mt-4 rounded-md border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/5 px-4 py-2.5 text-xs text-zinc-600 dark:text-zinc-300 motion-safe:animate-fade-slide-up" style="animation-delay: 110ms;">
            {{ __('Reversed by') }}
            <a href="{{ route('accounting.journal.show', $this->reversedBy) }}" wire:navigate class="font-medium underline underline-offset-2">{{ $this->reversedBy->journal_number }}</a>
        </div>
    @endif

    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 130ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">{{ __('Lines') }}</h2>

        <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Account') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Debit') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Credit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($journalEntry->lines as $line)
                        <tr wire:key="line-{{ $line->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                            <td class="py-2 px-3">
                                <p class="font-data text-accent">{{ $line->account->account_code }}</p>
                                <p class="text-zinc-700 dark:text-zinc-300">{{ $line->account->account_name }}</p>
                            </td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white">{{ bccomp((string) $line->debit, '0', 2) > 0 ? $this->formatRupiah((string) $line->debit) : '—' }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white">{{ bccomp((string) $line->credit, '0', 2) > 0 ? $this->formatRupiah((string) $line->credit) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-zinc-900 dark:border-white font-semibold">
                        <td class="py-2 px-3 text-zinc-900 dark:text-white">{{ __('Total') }}</td>
                        <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($journalEntry->totalDebit()) }}</td>
                        <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $journalEntry->lines->sum('credit')) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</section>
