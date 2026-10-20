<?php

use App\Enums\JournalStatus;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Jurnal Umum')] class extends Component {
    use WithPagination;

    #[Url]
    public string $period = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', JournalEntry::class);

        if (! $this->isValidPeriod($this->period)) {
            $this->period = now()->format('Y-m');
        }
    }

    public function updatedPeriod(): void
    {
        if (! $this->isValidPeriod($this->period)) {
            $this->period = now()->format('Y-m');
        }

        $this->resetPage();
    }

    protected function isValidPeriod(string $period): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1;
    }

    public function shiftPeriod(int $months): void
    {
        $this->period = $this->periodStart()->addMonthsNoOverflow($months)->format('Y-m');
        $this->resetPage();
    }

    protected function periodStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->period.'-01')->startOfDay();
    }

    protected function inPeriod(Builder $query, string $column = 'entry_date'): Builder
    {
        $start = $this->periodStart();

        return $query->whereBetween($column, [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()]);
    }

    #[Computed]
    public function periodLabel(): string
    {
        return $this->periodStart()->translatedFormat('F Y');
    }

    #[Computed]
    public function entries(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->inPeriod(JournalEntry::query())
            ->with(['lines' => fn ($q) => $q->orderByDesc('debit')->orderBy('id'), 'lines.account'])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->paginate(25);
    }

    #[Computed]
    public function periodTotals(): array
    {
        $totals = JournalEntryLine::query()
            ->whereHas('journalEntry', fn (Builder $q) => $this->inPeriod($q))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        return ['debit' => (string) $totals->debit, 'credit' => (string) $totals->credit];
    }

    public function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
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

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('accounting.dashboard')" wire:navigate>{{ __('Accounting') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('General Journal') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('General Journal') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>
        @can('create', App\Models\JournalEntry::class)
            <flux:button variant="primary" icon="plus" :href="route('accounting.journal.create')" wire:navigate class="active:scale-[0.95]">{{ __('New Entry') }}</flux:button>
        @endcan
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-2 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
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

    <div wire:loading.class="opacity-50" wire:target="period,shiftPeriod,gotoPage,previousPage,nextPage" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full min-w-[44rem] text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3 w-24">{{ __('Date') }}</th>
                        <th class="py-2 px-3 w-36">{{ __('Number') }}</th>
                        <th class="py-2 px-3">{{ __('Account') }}</th>
                        <th class="py-2 px-3 w-24">{{ __('Code') }}</th>
                        <th class="py-2 px-3 w-36 text-right">{{ __('Debit') }}</th>
                        <th class="py-2 px-3 w-36 text-right">{{ __('Credit') }}</th>
                    </tr>
                </thead>
                @forelse ($this->entries as $entry)
                    @php($reversed = $entry->status === JournalStatus::Reversed)
                    <tbody wire:key="je-{{ $entry->id }}" class="border-b border-zinc-200 dark:border-white/10 last:border-0 {{ $reversed ? 'opacity-60' : '' }}">
                        @foreach ($entry->lines as $line)
                            @php($isDebit = bccomp((string) $line->debit, '0', 2) > 0)
                            <tr>
                                <td class="pt-2 px-3 align-top font-data tabular-nums text-zinc-700 dark:text-zinc-300 whitespace-nowrap">{{ $loop->first ? $entry->entry_date->format('d/m/Y') : '' }}</td>
                                <td class="pt-2 px-3 align-top whitespace-nowrap">
                                    @if ($loop->first)
                                        <a href="{{ route('accounting.journal.show', $entry) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $entry->journal_number }}</a>
                                    @endif
                                </td>
                                <td class="pt-2 px-3 text-zinc-800 dark:text-zinc-200 {{ $isDebit ? '' : 'ps-10' }}">{{ $line->account->account_name }}</td>
                                <td class="pt-2 px-3 font-data text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ $line->account->account_code }}</td>
                                <td class="pt-2 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $isDebit ? $this->formatRupiah((string) $line->debit) : '' }}</td>
                                <td class="pt-2 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $isDebit ? '' : $this->formatRupiah((string) $line->credit) }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td colspan="2"></td>
                            <td colspan="4" class="pt-1 pb-2.5 px-3 italic text-zinc-500 dark:text-zinc-400">
                                ({{ $entry->description }})
                                @if ($reversed)
                                    <span class="not-italic ms-1.5 inline-flex items-center rounded-full border px-1.5 py-0.5 text-[10px] font-medium bg-zinc-100 text-zinc-500 border-zinc-200 dark:bg-white/5 dark:text-zinc-500 dark:border-white/10">{{ $entry->status->label() }}</span>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                @empty
                    <tbody>
                        <tr><td colspan="6" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No journal entries in this period.') }}</td></tr>
                    </tbody>
                @endforelse
                <tfoot>
                    @php($balanced = bccomp($this->periodTotals['debit'], $this->periodTotals['credit'], 2) === 0)
                    <tr class="border-t-2 border-zinc-300 dark:border-white/20 bg-zinc-50 dark:bg-white/5">
                        <td colspan="4" class="py-2.5 px-3 text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                            {{ __('Total for :period', ['period' => $this->periodLabel]) }}
                            @if ($balanced)
                                <span class="ms-2 inline-flex items-center gap-1 font-medium text-green-700 dark:text-green-400"><flux:icon.check-circle variant="micro" class="size-3.5" />{{ __('Balanced') }}</span>
                            @else
                                <span class="ms-2 inline-flex items-center gap-1 font-medium text-red-700 dark:text-red-400"><flux:icon.exclamation-triangle variant="micro" class="size-3.5" />{{ __('Not balanced') }}</span>
                            @endif
                        </td>
                        <td class="py-2.5 px-3 text-right font-data font-semibold tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatRupiah($this->periodTotals['debit']) }}</td>
                        <td class="py-2.5 px-3 text-right font-data font-semibold tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatRupiah($this->periodTotals['credit']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="mt-4">{{ $this->entries->links() }}</div>
    </div>
</section>
