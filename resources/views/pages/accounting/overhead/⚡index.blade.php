<?php

use App\Actions\Accounting\CancelOverheadExpenseAction;
use App\Actions\Accounting\CloseOverheadPeriodAction;
use App\Actions\Accounting\RecordOverheadExpenseAction;
use App\Enums\AccountType;
use App\Enums\JournalStatus;
use App\Enums\OverheadCategory;
use App\Exceptions\InvalidJournalEntryException;
use App\Exceptions\InvalidOverheadEntryException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Support\JournalPreview;
use App\Support\OverheadPeriod;
use App\Support\Reports\ReportPeriod;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Actual Overhead')] class extends Component {
    #[Url]
    public string $period = '';

    public string $category = 'electricity';

    public string $description = '';

    public string $amount = '';

    public string $creditAccountId = '';

    public function mount(): void
    {
        Gate::authorize('overhead.manage');
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
    public function month(): OverheadPeriod
    {
        return new OverheadPeriod($this->period);
    }

    #[Computed]
    public function categories(): array
    {
        return $this->month->categories();
    }

    #[Computed]
    public function expenses(): \Illuminate\Support\Collection
    {
        return $this->month->expenses()->whereNull('reversal_of_id')->values();
    }

    #[Computed]
    public function closing(): ?JournalEntry
    {
        return $this->month->closingEntry();
    }

    #[Computed]
    public function creditAccounts(): array
    {
        $reserved = array_map(fn ($r) => $r->value, RecordOverheadExpenseAction::reservedRoles());

        return Account::active()
            ->whereIn('type', [AccountType::Asset->value, AccountType::Liability->value])
            ->where(fn ($q) => $q->whereNull('system_role')->orWhereNotIn('system_role', $reserved))
            ->orderBy('account_code')
            ->get()
            ->map(fn (Account $a) => ['id' => (string) $a->id, 'title' => $a->account_name, 'subtitle' => $a->account_code, 'meta' => $a->type->label(), 'disabled_reason' => null])
            ->all();
    }

    #[Computed]
    public function entryPreview(): array
    {
        $amount = is_numeric($this->amount) && bccomp((string) $this->amount, '0', 2) > 0 ? bcadd((string) $this->amount, '0', 2) : '0';
        $category = OverheadCategory::tryFrom($this->category) ?? OverheadCategory::Electricity;
        $credit = filled($this->creditAccountId) ? (int) $this->creditAccountId : null;

        return JournalPreview::resolve(RecordOverheadExpenseAction::plan($category, $amount, $credit), ['debit', 'credit'], __('Choose the account to credit'));
    }

    #[Computed]
    public function closingPreview(): array
    {
        return JournalPreview::resolve($this->month->closingPlan());
    }

    public function openEntry(): void
    {
        $this->resetValidation();
        $this->reset('description', 'amount');
        $this->modal('record-overhead')->show();
    }

    public function record(): void
    {
        Gate::authorize('overhead.manage');

        $this->validate([
            'category' => ['required', Rule::enum(OverheadCategory::class)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'creditAccountId' => ['required', 'exists:accounts,id'],
        ], [], [
            'category' => __('Category'),
            'description' => __('Description'),
            'amount' => __('Amount'),
            'creditAccountId' => __('Credit account'),
        ]);

        try {
            app(RecordOverheadExpenseAction::class)->handle($this->month, OverheadCategory::from($this->category), $this->description, (string) $this->amount, (int) $this->creditAccountId, Auth::id());
        } catch (InvalidOverheadEntryException|InvalidJournalEntryException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        unset($this->categories, $this->expenses, $this->closingPreview);
        $this->modal('record-overhead')->close();
        Flux::toast(variant: 'success', text: __('Overhead recorded for :period.', ['period' => $this->month->label()]));
    }

    public ?int $pendingEntryId = null;

    public function openCancelEntry(int $entryId): void
    {
        $this->pendingEntryId = $entryId;
        $this->modal('cancel-entry')->show();
    }

    public function pendingEntryPlan(): array
    {
        return $this->pendingEntryId ? JournalPreview::reversalOf(JournalEntry::find($this->pendingEntryId)) : [];
    }

    public function cancelEntry(int $entryId): void
    {
        Gate::authorize('overhead.manage');

        try {
            app(CancelOverheadExpenseAction::class)->handle(JournalEntry::findOrFail($entryId), Auth::id());
        } catch (InvalidOverheadEntryException|InvalidJournalEntryException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        unset($this->categories, $this->expenses, $this->closingPreview);
        $this->modal('cancel-entry')->close();
        Flux::toast(variant: 'success', text: __('Entry cancelled with a reversing journal.'));
    }

    public function close(): void
    {
        Gate::authorize('overhead.manage');

        try {
            $entry = app(CloseOverheadPeriodAction::class)->handle($this->month, Auth::id());
        } catch (InvalidOverheadEntryException|InvalidJournalEntryException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        unset($this->categories, $this->expenses, $this->closing, $this->closingPreview);
        $this->modal('close-period')->close();
        Flux::toast(variant: 'success', text: __(':period closed (:number).', ['period' => $this->month->label(), 'number' => $entry->journal_number]));
    }

    public function rupiah(string|float $value): string
    {
        return ((float) $value < 0 ? '−Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.');
    }
}; ?>

@php($month = $this->month)
@php($closed = $this->closing !== null)
@php($applied = $month->applied())
@php($actual = $month->actualTotal())
@php($variance = bcsub($actual, $applied, 2))
@php($budgetTotal = collect($this->categories)->reduce(fn ($c, $r) => bcadd($c, $r['budget'], 2), '0'))
@php($canRecord = ! $closed && ! $month->isFuture())
@php($canClose = ! $closed && $month->isFinished())
@php($label = 'text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('accounting.dashboard')" wire:navigate>{{ __('Accounting') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Actual Overhead') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Actual Overhead') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Record the real factory overhead bills per month, then close the month against the overhead already charged to production.') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($canRecord)
                <flux:button variant="primary" icon="plus" wire:click="openEntry" class="active:scale-[0.95]">{{ __('Record overhead') }}</flux:button>
            @endif
            @if ($canClose)
                <flux:modal.trigger name="close-period"><flux:button variant="filled" icon="lock-closed" class="active:scale-[0.95]">{{ __('Close :period', ['period' => $month->label()]) }}</flux:button></flux:modal.trigger>
            @endif
        </div>
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
        <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ([
                [__('Budget'), $this->rupiah($budgetTotal), __('from the overhead rates')],
                [__('Applied to production'), $this->rupiah($applied), __('BOP Dibebankan this month')],
                [__('Actual'), $this->rupiah($actual), __('bills recorded this month')],
                [bccomp($variance, '0', 2) > 0 ? __('Under-applied') : (bccomp($variance, '0', 2) < 0 ? __('Over-applied') : __('Difference')), $this->rupiah(abs((float) $variance)), $closed ? __('moved to HPP at closing') : __('will move to HPP at closing')],
            ] as [$tileLabel, $value, $note])
                <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-4">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $tileLabel }}</p>
                    <p class="mt-2 text-xl font-semibold text-zinc-900 dark:text-white">{{ $value }}</p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $note }}</p>
                </div>
            @endforeach
        </div>

        <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Per category') }}</h2>
        <div class="mt-3 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full min-w-[40rem] text-xs border-collapse">
                <thead>
                    <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3 {{ $label }}">{{ __('Category') }}</th>
                        <th class="py-2 px-3 {{ $label }} text-right">{{ __('Budget') }}</th>
                        <th class="py-2 px-3 {{ $label }} text-right">{{ __('Actual') }}</th>
                        <th class="py-2 px-3 {{ $label }} text-right">{{ __('Actual vs budget') }}</th>
                        <th class="py-2 px-3 {{ $label }}">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->categories as $row)
                        @php($diff = bcsub($row['actual'], $row['budget'], 2))
                        @php($missing = bccomp($row['actual'], '0', 2) === 0 && bccomp($row['budget'], '0', 2) > 0)
                        <tr class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ $row['category']->label() }}</td>
                            <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-500 dark:text-zinc-400">{{ $this->rupiah($row['budget']) }}</td>
                            <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap font-medium text-zinc-900 dark:text-white">{{ $this->rupiah($row['actual']) }}</td>
                            <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap {{ $missing ? 'text-zinc-400' : (bccomp($diff, '0', 2) > 0 ? 'text-red-700 dark:text-red-400' : 'text-zinc-600 dark:text-zinc-300') }}">{{ $missing ? '—' : (bccomp($diff, '0', 2) > 0 ? '+' : '').$this->rupiah($diff) }}</td>
                            <td class="py-2.5 px-3">
                                @if ($missing && ! $closed)
                                    <span class="inline-flex items-center rounded-full border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:text-amber-400">{{ __('Not recorded yet') }}</span>
                                @elseif ($row['entries'] > 0)
                                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count entry|:count entries', $row['entries'], ['count' => $row['entries']]) }}</span>
                                @else
                                    <span class="text-[11px] text-zinc-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Budget = the monthly amounts in the overhead rates of all work centers. Applied = overhead charged to production results this month (machine hours × rate).') }}</p>

        <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Recorded bills') }}</h2>
        <div class="mt-3 rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5">
            @forelse ($this->expenses as $entry)
                @php($debit = $entry->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 2) > 0))
                @php($credit = $entry->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0))
                @php($reversed = $entry->status === JournalStatus::Reversed)
                <div wire:key="oh-{{ $entry->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-xs {{ $reversed ? 'opacity-50' : '' }}">
                    <div class="min-w-0">
                        <p class="text-zinc-900 dark:text-white {{ $reversed ? 'line-through' : '' }}">{{ $entry->description }}</p>
                        <p class="mt-0.5 text-zinc-500 dark:text-zinc-400">
                            {{ $debit?->account->account_name }} ← {{ $credit?->account->account_name }} ·
                            <a href="{{ route('accounting.journal.show', $entry) }}" wire:navigate class="font-data text-accent hover:underline">{{ $entry->journal_number }}</a>
                            @if ($reversed) · {{ __('Cancelled') }}@endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="font-data tabular-nums font-medium text-zinc-900 dark:text-white">{{ $this->rupiah((string) ($debit?->debit ?? 0)) }}</span>
                        @if (! $reversed && ! $closed)
                            <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" wire:click="openCancelEntry({{ $entry->id }})" :aria-label="__('Cancel entry')" class="text-zinc-400 hover:text-red-600 active:scale-[0.95]" />
                        @endif
                    </div>
                </div>
            @empty
                <p class="py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No overhead recorded for :period yet.', ['period' => $month->label()]) }}</p>
            @endforelse
        </div>
    </div>

    <flux:modal name="record-overhead" class="max-w-lg">
        <form wire:submit="record" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Record overhead') }}</flux:heading>
                <flux:subheading>{{ __('For :period — dated :date.', ['period' => $month->label(), 'date' => \Illuminate\Support\Carbon::parse($month->expenseDate())->translatedFormat('j M Y')]) }}</flux:subheading>
            </div>
            <flux:select wire:model.live="category" :label="__('Category')">
                @foreach (OverheadCategory::cases() as $c)
                    <option value="{{ $c->value }}">{{ $c->label() }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model="description" :label="__('Description')" :placeholder="__('e.g. PLN bill October')" />
            <flux:field>
                <flux:label>{{ __('Amount') }}</flux:label>
                <flux:input.group>
                    <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                    <flux:input type="text" inputmode="decimal" wire:model.live.debounce.400ms="amount" input:class="text-right font-data tabular-nums" :loading="false" />
                </flux:input.group>
                @error('amount') <flux:error :message="$message" /> @enderror
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Credit account') }}</flux:label>
                <x-picker model="creditAccountId" size="md" :options="$this->creditAccounts" :selected="$creditAccountId" :placeholder="__('Cash, bank, payable or accumulated depreciation...')" :label="__('Credit account')" :invalid="$errors->has('creditAccountId')" />
                <flux:description>{{ __('Kas/Bank if it was paid, a payable if it is billed, Akumulasi Penyusutan for depreciation.') }}</flux:description>
                @error('creditAccountId') <flux:error class="mt-1" :message="$message" /> @enderror
            </flux:field>
            @php($entryPreview = $this->entryPreview)
            @php($entryUnmapped = collect($entryPreview)->where('unmapped', true))
            <div>
                <p class="mb-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ __('Journal entry') }}</p>
                <div wire:loading.class="opacity-60" wire:target="amount,category,creditAccountId" class="transition-opacity">
                    <x-journal-preview :lines="$entryPreview" />
                </div>
                @if ($entryUnmapped->isNotEmpty())
                    <p class="mt-2 flex items-start gap-1.5 rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                        <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-3.5 shrink-0" />
                        {{ __('Map these system roles on the Chart of Accounts page before posting: :roles.', ['roles' => $entryUnmapped->pluck('name')->implode(', ')]) }}
                    </p>
                @endif
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="record" :disabled="$entryUnmapped->isNotEmpty()">{{ __('Record') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="close-period" class="max-w-xl">
        @php($closePreview = $this->closingPreview)
        @php($closeUnmapped = collect($closePreview)->where('unmapped', true))
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Close :period?', ['period' => $month->label()]) }}</flux:heading>
                <flux:subheading>{{ __('This closing entry is posted on :date. After closing, no more overhead can be recorded for this month.', ['date' => $month->end->translatedFormat('j M Y')]) }}</flux:subheading>
            </div>
            @if ($closePreview === [])
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('There is no applied or actual overhead in :period, so there is nothing to close.', ['period' => $month->label()]) }}</p>
            @else
                <x-journal-preview :lines="$closePreview" />
                <p class="text-xs text-zinc-600 dark:text-zinc-300">
                    @if (bccomp($variance, '0', 2) > 0)
                        {{ __('Actual overhead was :amount more than what was charged to production (under-applied) — the difference goes to HPP.', ['amount' => $this->rupiah($variance)]) }}
                    @elseif (bccomp($variance, '0', 2) < 0)
                        {{ __('Production was charged :amount more than the actual overhead (over-applied) — the difference reduces HPP.', ['amount' => $this->rupiah(abs((float) $variance))]) }}
                    @else
                        {{ __('Applied and actual overhead match exactly — no difference to move.') }}
                    @endif
                </p>
            @endif
            @if ($closeUnmapped->isNotEmpty())
                <p class="flex items-start gap-1.5 rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                    <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-3.5 shrink-0" />
                    {{ __('Map these system roles on the Chart of Accounts page before posting: :roles.', ['roles' => $closeUnmapped->pluck('name')->implode(', ')]) }}
                </p>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="close" wire:loading.attr="disabled" wire:target="close" :disabled="$closePreview === [] || $closeUnmapped->isNotEmpty()">{{ __('Close period') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="cancel-entry" class="max-w-xl">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Cancel this overhead entry?') }}</flux:heading>
                <flux:subheading>{{ __('A reversing journal entry is posted in the same month.') }}</flux:subheading>
            </div>
            <x-journal-confirm :plan="$this->pendingEntryPlan()" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="cancelEntry($pendingEntryId)" wire:loading.attr="disabled" wire:target="cancelEntry">{{ __('Cancel entry') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
