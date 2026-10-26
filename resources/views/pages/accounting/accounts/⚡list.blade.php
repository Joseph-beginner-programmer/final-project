<?php

use App\Actions\MasterData\DeleteAccountAction;
use App\Actions\MasterData\SaveAccountAction;
use App\Enums\AccountSystemRole;
use App\Enums\AccountType;
use App\Models\Account;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Chart of Accounts')] class extends Component {
    use WithPagination;

    #[Url]
    public string $type = '';

    #[Url]
    public string $search = '';

    #[Url]
    public bool $showInactive = false;

    #[Locked]
    public ?int $editingId = null;

    public string $accountCode = '';

    public string $accountName = '';

    public string $accountType = 'asset';

    public ?string $systemRole = null;

    public bool $isActive = true;

    public bool $isContra = false;

    public function mount(): void
    {
        Gate::authorize('viewAny', Account::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    protected function filtered(): Builder
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return Account::query()
            ->when(! $this->showInactive, fn (Builder $q) => $q->where('is_active', true))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('account_name', 'like', "%{$term}%")
                ->orWhere('account_code', 'like', "%{$term}%")));
    }

    #[Computed]
    public function accounts(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->filtered()
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
            ->orderBy('account_code')
            ->paginate(20);
    }

    #[Computed]
    public function typeCounts(): array
    {
        return $this->filtered()->selectRaw('type, count(*) as count')->groupBy('type')->pluck('count', 'type')->all();
    }

    #[Computed]
    public function typeOptions(): array
    {
        return collect(AccountType::cases())->map(fn (AccountType $type) => [
            'id' => $type->value,
            'title' => $type->label(),
            'subtitle' => __('Normal balance').': '.$type->normalBalance()->label(),
            'meta' => null,
            'disabled_reason' => null,
        ])->all();
    }

    #[Computed]
    public function mappedSystemRoles(): array
    {
        return Account::whereNotNull('system_role')->pluck('system_role')->map(fn ($role) => $role->value)->all();
    }

    #[Computed]
    public function systemRoleOptions(): array
    {
        $takenByOthers = collect($this->mappedSystemRoles)->diff($this->editingId
            ? [Account::find($this->editingId)?->system_role?->value]
            : []);

        $none = [[
            'id' => '',
            'title' => __('None'),
            'subtitle' => __('Not used by automatic journals'),
            'meta' => null,
            'disabled_reason' => null,
        ]];

        $roles = collect(AccountSystemRole::cases())->map(fn (AccountSystemRole $role) => [
            'id' => $role->value,
            'title' => $role->label(),
            'subtitle' => $role->hint(),
            'meta' => null,
            'disabled_reason' => $takenByOthers->contains($role->value) ? __('Already assigned to another account') : null,
        ])->all();

        return [...$none, ...$roles];
    }

    public function openCreate(): void
    {
        Gate::authorize('create', Account::class);

        $this->resetForm();
        $this->accountType = $this->type !== '' ? $this->type : AccountType::Asset->value;
        $this->modal('account-form')->show();
    }

    public function openEdit(int $id): void
    {
        $account = Account::findOrFail($id);
        Gate::authorize('update', $account);

        $this->resetValidation();
        $this->editingId = $account->id;
        $this->accountCode = $account->account_code;
        $this->accountName = $account->account_name;
        $this->accountType = $account->type->value;
        $this->systemRole = $account->system_role?->value;
        $this->isActive = $account->is_active;
        $this->isContra = $account->is_contra;
        $this->modal('account-form')->show();
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'accountCode', 'accountName', 'accountType', 'systemRole', 'isActive', 'isContra');
    }

    protected function rules(): array
    {
        return [
            'accountCode' => ['required', 'string', 'max:20', Rule::unique('accounts', 'account_code')->ignore($this->editingId)],
            'accountName' => ['required', 'string', 'max:255'],
            'accountType' => ['required', Rule::enum(AccountType::class)],
            'systemRole' => ['nullable', Rule::enum(AccountSystemRole::class), Rule::unique('accounts', 'system_role')->ignore($this->editingId)],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'accountCode' => __('Account code'),
            'accountName' => __('Account name'),
            'accountType' => __('Category'),
            'systemRole' => __('System role'),
        ];
    }

    public function save(): void
    {
        $this->systemRole = $this->systemRole ?: null;
        $this->validate();

        $account = $this->editingId ? Account::findOrFail($this->editingId) : null;
        $account ? Gate::authorize('update', $account) : Gate::authorize('create', Account::class);

        $fields = [
            'account_code' => $this->accountCode,
            'account_name' => $this->accountName,
            'type' => AccountType::from($this->accountType),
            'system_role' => $this->systemRole ? AccountSystemRole::from($this->systemRole) : null,
            'is_active' => $this->isActive,
            'is_contra' => $this->isContra,
        ];

        app(SaveAccountAction::class)->handle($fields, $account);

        Flux::toast(variant: 'success', text: $account ? __('Account updated.') : __('Account added.'));
        $this->modal('account-form')->close();
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $account = Account::findOrFail($id);
        Gate::authorize('delete', $account);

        app(DeleteAccountAction::class)->handle($account);

        Flux::toast(variant: 'success', text: __('Account deleted.'));
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
        <flux:breadcrumbs.item>{{ __('Chart of Accounts') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Chart of Accounts') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>
        @can('create', Account::class)
            <flux:button variant="primary" icon="plus" wire:click="openCreate" class="active:scale-[0.95]">{{ __('New Account') }}</flux:button>
        @endcan
    </div>

    <div class="mt-6 flex items-center gap-1.5 overflow-x-auto pb-1 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <button type="button" wire:click="$set('type', '')"
            class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                {{ $type === '' ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
            {{ __('All') }}
            <span class="font-data tabular-nums {{ $type === '' ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ array_sum($this->typeCounts) }}</span>
        </button>
        @foreach (AccountType::cases() as $option)
            <button type="button" wire:click="$set('type', '{{ $option->value }}')"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                    {{ $type === $option->value ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
                {{ $option->label() }}
                <span class="font-data tabular-nums {{ $type === $option->value ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->typeCounts[$option->value] ?? 0 }}</span>
            </button>
        @endforeach
    </div>
    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    <div class="mt-4 flex flex-wrap items-center gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <div class="flex-1 min-w-56">
            <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search name or code...')" />
        </div>
        <flux:switch wire:model.live="showInactive" :label="__('Show inactive')" align="left" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,type,showInactive,gotoPage,previousPage,nextPage" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
        <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Code') }}</th>
                        <th class="py-2 px-3">{{ __('Account') }}</th>
                        <th class="py-2 px-3">{{ __('Category') }}</th>
                        <th class="py-2 px-3">{{ __('Normal balance') }}</th>
                        <th class="py-2 px-3">{{ __('Status') }}</th>
                        <th class="py-2 px-3 w-20"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->accounts as $account)
                        <tr wire:key="account-{{ $account->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors {{ $account->is_active ? '' : 'opacity-60' }}">
                            <td class="py-2 px-3 font-data text-accent whitespace-nowrap">{{ $account->account_code }}</td>
                            <td class="py-2 px-3 text-zinc-800 dark:text-zinc-200">
                                {{ $account->account_name }}
                                @if ($account->system_role)
                                    <span class="ml-1.5 inline-flex items-center rounded-full bg-accent/10 px-1.5 py-0.5 font-data text-[10px] text-accent">{{ $account->system_role->label() }}</span>
                                @endif
                            </td>
                            <td class="py-2 px-3"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap {{ $account->type->badgeClasses() }}">{{ $account->type->label() }}</span></td>
                            <td class="py-2 px-3 text-zinc-600 dark:text-zinc-300 whitespace-nowrap">
                                {{ $account->normal_balance->label() }}
                                @if ($account->is_contra)
                                    <span class="ml-1 inline-flex items-center rounded-full border border-zinc-300 dark:border-white/20 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 dark:text-zinc-300">{{ __('Contra') }}</span>
                                @endif
                            </td>
                            <td class="py-2 px-3">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $account->is_active ? 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20' : 'bg-zinc-100 text-zinc-500 border-zinc-200 dark:bg-white/5 dark:text-zinc-500 dark:border-white/10' }}">{{ $account->is_active ? __('Active') : __('Inactive') }}</span>
                            </td>
                            <td class="py-2 px-3 text-right whitespace-nowrap">
                                @can('update', $account)
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEdit({{ $account->id }})" :aria-label="__('Edit :name', ['name' => $account->account_name])" class="active:scale-[0.95]" />
                                @endcan
                                @can('delete', $account)
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $account->id }})" wire:confirm="{{ __('Delete :name? This cannot be undone.', ['name' => $account->account_name]) }}" :aria-label="__('Delete :name', ['name' => $account->account_name])" class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 active:scale-[0.95]" />
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No accounts match your filters.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @php($mappedCount = count($this->mappedSystemRoles))
        @php($totalRoles = count(\App\Enums\AccountSystemRole::cases()))
        <div class="mt-3 flex items-center justify-between gap-3 rounded-lg border px-4 py-2.5 text-xs {{ $mappedCount === $totalRoles ? 'border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-white/5' : 'border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10' }}">
            <span class="{{ $mappedCount === $totalRoles ? 'text-zinc-500 dark:text-zinc-400' : 'text-amber-800 dark:text-amber-300' }}">
                @if ($mappedCount < $totalRoles)
                    <flux:icon.exclamation-triangle variant="micro" class="inline size-3.5 -mt-0.5" />
                @endif
                {{ __(':mapped of :total system roles mapped — unmapped roles will block automatic journal posting.', ['mapped' => $mappedCount, 'total' => $totalRoles]) }}
            </span>
        </div>

        <div class="mt-4">{{ $this->accounts->links() }}</div>
    </div>

    <flux:modal name="account-form" focusable class="max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit Account') : __('New Account') }}</flux:heading>
                <flux:subheading>{{ __('Normal balance is set automatically from the category, or the opposite side for a contra account.') }}</flux:subheading>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 items-start gap-4">
                <flux:input wire:model="accountCode" :label="__('Account code')" :placeholder="__('e.g. 1-1100')" />
                <flux:field>
                    <flux:label>{{ __('Category') }}</flux:label>
                    <x-picker model="accountType" size="md" :options="$this->typeOptions" :selected="$accountType" :placeholder="__('Select category...')" :label="__('Category')" :invalid="$errors->has('accountType')" />
                    @error('accountType') <flux:error class="mt-1" :message="$message" /> @enderror
                </flux:field>
            </div>

            <flux:input wire:model="accountName" :label="__('Account name')" :placeholder="__('e.g. Kas')" />

            @php($previewType = \App\Enums\AccountType::tryFrom($accountType))
            <flux:checkbox wire:model.live="isContra" :label="__('Contra account')" :description="__('A \'minus\' account inside its category, e.g. Akumulasi Penyusutan under Harta or BOP Dibebankan under Beban. Its normal balance is the opposite side of the category.')" />

            @if ($previewType)
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 text-xs dark:border-white/10 dark:bg-white/5">
                    <span class="text-zinc-600 dark:text-zinc-300">{{ __('Normal balance') }}</span>
                    <span class="font-data font-semibold text-zinc-900 dark:text-white">{{ $previewType->normalBalanceFor($isContra)->label() }}</span>
                    @if ($isContra)
                        <span class="text-zinc-500 dark:text-zinc-400">· {{ __('opposite of :category (:side)', ['category' => $previewType->label(), 'side' => $previewType->normalBalance()->label()]) }}</span>
                    @endif
                </div>
            @endif

            <flux:field>
                <flux:label>{{ __('System role') }}</flux:label>
                <x-picker model="systemRole" size="md" :options="$this->systemRoleOptions" :selected="(string) $systemRole" :placeholder="__('None — not used by automatic journals')" :label="__('System role')" :invalid="$errors->has('systemRole')" />
                <flux:description>{{ __('Optional — tells automatic journal postings which real account to use for this role (e.g. raw material inventory).') }}</flux:description>
                @error('systemRole') <flux:error class="mt-1" :message="$message" /> @enderror
            </flux:field>

            <flux:switch wire:model="isActive" :label="__('Active')" :description="__('Inactive accounts disappear from pickers but stay in history.')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
