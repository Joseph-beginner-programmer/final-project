<?php

use App\Actions\Accounting\PostJournalEntryAction;
use App\Enums\JournalEntryType;
use App\Exceptions\InvalidJournalEntryException;
use App\Models\Account;
use App\Models\JournalEntry;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New Journal Entry')] class extends Component {
    public string $entryType = 'manual';

    public string $description = '';

    public array $lines = [];

    public function mount(): void
    {
        Gate::authorize('create', JournalEntry::class);

        $this->addRow();
        $this->addRow();
    }

    public function addRow(): void
    {
        $this->lines[] = ['account_id' => '', 'debit' => '', 'credit' => ''];
    }

    public function removeRow(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function updated(string $property, mixed $value): void
    {
        if (! preg_match('/^lines\.(\d+)\.(debit|credit)$/', $property, $match)) {
            return;
        }

        if (! is_numeric($value) || bccomp((string) $value, '0', 2) <= 0) {
            return;
        }

        $opposite = $match[2] === 'debit' ? 'credit' : 'debit';
        $this->lines[(int) $match[1]][$opposite] = '';
        $this->resetValidation("lines.{$match[1]}.{$opposite}");
    }

    #[Computed]
    public function accountOptions(): array
    {
        return Account::active()->orderBy('account_code')->get()->map(fn (Account $account) => [
            'id' => (string) $account->id,
            'title' => $account->account_name,
            'subtitle' => $account->account_code,
            'meta' => $account->type->label(),
            'disabled_reason' => null,
        ])->all();
    }

    public function rowTotal(string $field): string
    {
        return collect($this->lines)->reduce(
            fn (string $carry, array $line) => bcadd($carry, is_numeric($line[$field] ?? null) ? (string) $line[$field] : '0', 2),
            '0'
        );
    }

    #[Computed]
    public function totalDebit(): string
    {
        return $this->rowTotal('debit');
    }

    #[Computed]
    public function totalCredit(): string
    {
        return $this->rowTotal('credit');
    }

    #[Computed]
    public function isBalanced(): bool
    {
        return bccomp($this->totalDebit, $this->totalCredit, 2) === 0 && bccomp($this->totalDebit, '0', 2) > 0;
    }

    public function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    protected function rules(): array
    {
        return [
            'entryType' => ['required', Rule::in([JournalEntryType::Manual->value, JournalEntryType::Opening->value])],
            'description' => ['nullable', 'string', 'max:255'],
            'lines.*.account_id' => ['required', 'exists:accounts,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'entryType' => __('Entry type'),
            'description' => __('Description'),
            'lines.*.account_id' => __('Account'),
            'lines.*.debit' => __('Debit'),
            'lines.*.credit' => __('Credit'),
        ];
    }

    public function confirmPost(): void
    {
        Gate::authorize('create', JournalEntry::class);
        $this->lines = array_values(array_filter($this->lines, fn (array $line) => filled($line['account_id']) || filled($line['debit']) || filled($line['credit'])));
        $this->validate();
        $this->modal('confirm-entry')->show();
    }

    public function entryPlan(): array
    {
        return collect($this->lines)
            ->filter(fn (array $line) => filled($line['account_id']))
            ->map(fn (array $line) => [
                'role' => null,
                'account_id' => (int) $line['account_id'],
                'debit' => is_numeric($line['debit']) ? bcadd((string) $line['debit'], '0', 2) : '0',
                'credit' => is_numeric($line['credit']) ? bcadd((string) $line['credit'], '0', 2) : '0',
            ])
            ->filter(fn (array $line) => bccomp($line['debit'], '0', 2) > 0 || bccomp($line['credit'], '0', 2) > 0)
            ->values()
            ->all();
    }

    public function save(): void
    {
        Gate::authorize('create', JournalEntry::class);

        $this->lines = array_values(array_filter($this->lines, fn (array $line) => filled($line['account_id']) || filled($line['debit']) || filled($line['credit'])));

        $this->validate();

        foreach ($this->lines as $index => $line) {
            if (bccomp((string) ($line['debit'] ?: '0'), '0', 2) > 0 && bccomp((string) ($line['credit'] ?: '0'), '0', 2) > 0) {
                $this->addError("lines.$index.credit", __('Fill either debit or credit on a line, not both.'));
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $type = JournalEntryType::from($this->entryType);
        $description = trim($this->description) !== '' ? trim($this->description) : ($type === JournalEntryType::Opening ? __('Opening balance') : __('Manual journal'));

        $lines = collect($this->lines)->map(fn (array $line) => [
            'account_id' => (int) $line['account_id'],
            'debit' => $line['debit'] ?: '0',
            'credit' => $line['credit'] ?: '0',
        ])->all();

        try {
            $entry = app(PostJournalEntryAction::class)->handle(
                lines: $lines,
                type: $type,
                description: $description,
                userId: Auth::id(),
            );
        } catch (InvalidJournalEntryException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Journal entry :number posted.', ['number' => $entry->journal_number]));
        $this->redirectRoute('accounting.journal.show', $entry, navigate: true);
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

<section class="w-full max-w-5xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('accounting.dashboard')" wire:navigate>{{ __('Accounting') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('accounting.journal.list')" wire:navigate>{{ __('General Journal') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('New Entry') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('New Journal Entry') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">{{ __('Posts immediately — corrections are made with a reversing entry, not an edit.') }}</p>
        </div>
        <flux:button variant="primary" wire:click="confirmPost" wire:loading.attr="disabled" wire:target="confirmPost" :disabled="! $this->isBalanced">
            {{ __('Post Entry') }}
        </flux:button>
    </div>

    <div class="mt-6 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 shadow-md shadow-zinc-900/4 dark:shadow-none p-6 sm:p-8 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <flux:radio.group wire:model.live="entryType" :label="__('Entry type')" variant="segmented" class="max-w-sm">
            <flux:radio value="manual" :label="__('Manual')" />
            <flux:radio value="opening" :label="__('Opening balance')" />
        </flux:radio.group>
        @error('entryType') <flux:error class="mt-1" :message="$message" /> @enderror

        <div class="mt-5">
            <flux:textarea wire:model="description" :label="__('Description')" :badge="__('Optional')" rows="2" :placeholder="__('What is this entry for?')" />
            @error('description') <flux:error class="mt-1" :message="$message" /> @enderror
        </div>

        <div class="mt-6 flex items-center justify-between">
            <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Lines') }}</h2>
            <flux:button size="sm" variant="ghost" icon="plus" wire:click="addRow" class="active:scale-[0.95]">{{ __('Add Row') }}</flux:button>
        </div>

        <div class="mt-3 rounded-md border border-zinc-200 dark:border-white/10">
            <div class="hidden md:grid grid-cols-[minmax(0,1fr)_9rem_9rem_2rem] gap-x-3 px-3 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10 rounded-t-md">
                <span>{{ __('Account') }}</span>
                <span class="text-right">{{ __('Debit') }}</span>
                <span class="text-right">{{ __('Credit') }}</span>
                <span></span>
            </div>

            @foreach ($lines as $index => $line)
                <div wire:key="je-row-{{ $index }}" class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_2rem] md:grid-cols-[minmax(0,1fr)_9rem_9rem_2rem] gap-x-3 gap-y-2.5 md:gap-y-0 items-start px-3 py-3 md:py-2.5 border-b border-zinc-100 dark:border-white/5 last:border-0">
                    <div class="col-span-2 md:col-span-1 min-w-0">
                        <x-picker :model="'lines.'.$index.'.account_id'" :options="$this->accountOptions" :selected="(string) $line['account_id']" :placeholder="__('Select account...')" :label="__('Account')" :invalid="$errors->has('lines.'.$index.'.account_id')" />
                        @error("lines.$index.account_id") <flux:error class="mt-1" :message="$message" /> @enderror
                    </div>

                    <div class="min-w-0">
                        <p class="md:hidden mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Debit') }}</p>
                        <flux:input.group>
                            <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" wire:model.live.debounce.400ms="lines.{{ $index }}.debit" :loading="false" />
                        </flux:input.group>
                        @error("lines.$index.debit") <flux:error class="mt-1" :message="$message" /> @enderror
                    </div>

                    <div class="min-w-0">
                        <p class="md:hidden mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Credit') }}</p>
                        <flux:input.group>
                            <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" wire:model.live.debounce.400ms="lines.{{ $index }}.credit" :loading="false" />
                        </flux:input.group>
                        @error("lines.$index.credit") <flux:error class="mt-1" :message="$message" /> @enderror
                    </div>

                    <div class="col-start-3 row-start-1 md:col-start-4 flex justify-end">
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeRow({{ $index }})" :aria-label="__('Remove')" class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 active:scale-[0.95]" />
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-end gap-4 rounded-lg border px-4 py-2.5 text-xs {{ $this->isBalanced ? 'border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-white/5' : 'border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10' }}">
            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Debit') }} <span class="font-data font-semibold tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatRupiah($this->totalDebit) }}</span></span>
            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Credit') }} <span class="font-data font-semibold tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatRupiah($this->totalCredit) }}</span></span>
            @if ($this->isBalanced)
                <span class="inline-flex items-center gap-1 font-medium text-green-700 dark:text-green-400"><flux:icon.check-circle variant="micro" class="size-3.5" />{{ __('Balanced') }}</span>
            @else
                <span class="inline-flex items-center gap-1 font-medium text-amber-800 dark:text-amber-300"><flux:icon.exclamation-triangle variant="micro" class="size-3.5" />{{ __('Not balanced yet') }}</span>
            @endif
        </div>
    </div>

    <flux:modal name="confirm-entry" class="max-w-xl">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Post this journal entry?') }}</flux:heading>
                <flux:subheading>{{ __('It posts immediately and cannot be edited — a mistake is corrected with a reversing entry.') }}</flux:subheading>
            </div>
            <x-journal-confirm :plan="$this->entryPlan()" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">{{ __('Post Entry') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
