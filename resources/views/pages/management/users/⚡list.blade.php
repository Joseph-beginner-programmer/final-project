<?php

use App\Actions\MasterData\SaveUserAction;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\UserRole;
use App\Models\User;
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

new #[Title('Manage Users')] class extends Component {
    use PasswordValidationRules, ProfileValidationRules, WithPagination;

    #[Url]
    public string $role = '';

    #[Url]
    public string $search = '';

    #[Url]
    public bool $showInactive = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $userRole = 'warehouse';

    public bool $isActive = true;

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', User::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingRole(): void
    {
        $this->resetPage();
    }

    protected function filtered(): Builder
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return User::query()
            ->when(! $this->showInactive, fn (Builder $q) => $q->where('is_active', true))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")));
    }

    #[Computed]
    public function users(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->filtered()
            ->when($this->role !== '', fn (Builder $q) => $q->where('role', $this->role))
            ->orderBy('name')
            ->paginate(20);
    }

    #[Computed]
    public function roleCounts(): array
    {
        return $this->filtered()->selectRaw('role, count(*) as count')->groupBy('role')->pluck('count', 'role')->all();
    }

    #[Computed]
    public function roleOptions(): array
    {
        return collect(UserRole::cases())->map(fn (UserRole $role) => [
            'id' => $role->value,
            'title' => $role->label(),
            'subtitle' => null,
            'meta' => null,
            'disabled_reason' => null,
        ])->all();
    }

    public function openCreate(): void
    {
        Gate::authorize('create', User::class);

        $this->resetForm();
        $this->userRole = $this->role !== '' ? $this->role : UserRole::Warehouse->value;
        $this->modal('user-form')->show();
    }

    public function openEdit(int $id): void
    {
        $user = User::findOrFail($id);
        Gate::authorize('update', $user);

        $this->resetValidation();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->userRole = $user->role->value;
        $this->isActive = $user->is_active;
        $this->password = '';
        $this->password_confirmation = '';
        $this->modal('user-form')->show();
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name', 'email', 'userRole', 'isActive', 'password', 'password_confirmation');
    }

    protected function rules(): array
    {
        return [
            ...$this->profileRules($this->editingId),
            'userRole' => ['required', Rule::enum(UserRole::class)],
            'password' => $this->editingId
                ? ['nullable', 'string', ...array_slice($this->passwordRules(), 1)]
                : $this->passwordRules(),
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'userRole' => __('Role'),
            'password' => __('Password'),
        ];
    }

    public function save(): void
    {
        $this->validate();

        $user = $this->editingId ? User::findOrFail($this->editingId) : null;
        $user ? Gate::authorize('update', $user) : Gate::authorize('create', User::class);

        $fields = [
            'name' => $this->name,
            'email' => $this->email,
            'role' => UserRole::from($this->userRole),
            'is_active' => $this->isActive,
            'password' => $this->password !== '' ? $this->password : null,
        ];

        app(SaveUserAction::class)->handle($fields, $user);

        Flux::toast(variant: 'success', text: $user ? __('User updated.') : __('User created.'));
        $this->modal('user-form')->close();
        $this->resetForm();
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
        <flux:breadcrumbs.item :href="route('executive.dashboard')" wire:navigate>{{ __('Management') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Users') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Users') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>
        @can('create', User::class)
            <flux:button variant="primary" icon="plus" wire:click="openCreate" class="active:scale-[0.95]">{{ __('New User') }}</flux:button>
        @endcan
    </div>

    <div class="mt-6 flex items-center gap-1.5 overflow-x-auto pb-1 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <button type="button" wire:click="$set('role', '')"
            class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                {{ $role === '' ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
            {{ __('All') }}
            <span class="font-data tabular-nums {{ $role === '' ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ array_sum($this->roleCounts) }}</span>
        </button>
        @foreach (UserRole::cases() as $option)
            <button type="button" wire:click="$set('role', '{{ $option->value }}')"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                    {{ $role === $option->value ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
                {{ $option->label() }}
                <span class="font-data tabular-nums {{ $role === $option->value ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->roleCounts[$option->value] ?? 0 }}</span>
            </button>
        @endforeach
    </div>
    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    <div class="mt-4 flex flex-wrap items-center gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <div class="flex-1 min-w-56">
            <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search name or email...')" />
        </div>
        <flux:switch wire:model.live="showInactive" :label="__('Show inactive')" align="left" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,role,showInactive,gotoPage,previousPage,nextPage" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
        <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Name') }}</th>
                        <th class="py-2 px-3">{{ __('Email') }}</th>
                        <th class="py-2 px-3">{{ __('Role') }}</th>
                        <th class="py-2 px-3">{{ __('Status') }}</th>
                        <th class="py-2 px-3 w-12"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->users as $user)
                        <tr wire:key="user-{{ $user->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors {{ $user->is_active ? '' : 'opacity-60' }}">
                            <td class="py-2 px-3 text-zinc-800 dark:text-zinc-200">{{ $user->name }} @if ($user->id === auth()->id()) <span class="text-[10px] text-zinc-400 dark:text-zinc-500">({{ __('you') }})</span> @endif</td>
                            <td class="py-2 px-3 font-data text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $user->email }}</td>
                            <td class="py-2 px-3"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap bg-zinc-100 text-zinc-700 border-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:border-white/10">{{ $user->role->label() }}</span></td>
                            <td class="py-2 px-3">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $user->is_active ? 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20' : 'bg-zinc-100 text-zinc-500 border-zinc-200 dark:bg-white/5 dark:text-zinc-500 dark:border-white/10' }}">{{ $user->is_active ? __('Active') : __('Inactive') }}</span>
                            </td>
                            <td class="py-2 px-3 text-right">
                                @can('update', $user)
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEdit({{ $user->id }})" :aria-label="__('Edit :name', ['name' => $user->name])" class="active:scale-[0.95]" />
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No users match your filters.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $this->users->links() }}</div>
    </div>

    <flux:modal name="user-form" focusable class="max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit User') : __('New User') }}</flux:heading>
                <flux:subheading>
                    @if (! $editingId)
                        {{ __('They can sign in immediately with the password you set below.') }}
                    @else
                        {{ __('Leave the password blank to keep it unchanged.') }}
                    @endif
                </flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Name')" />
            <flux:input type="email" wire:model="email" :label="__('Email')" />

            <flux:field>
                <flux:label>{{ __('Role') }}</flux:label>
                <x-picker model="userRole" size="md" :options="$this->roleOptions" :selected="$userRole" :placeholder="__('Select role...')" :label="__('Role')" :invalid="$errors->has('userRole')" />
                @error('userRole') <flux:error class="mt-1" :message="$message" /> @enderror
            </flux:field>

            <flux:switch wire:model="isActive" :label="__('Active')" />

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:input type="password" wire:model="password" :label="$editingId ? __('New password') : __('Password')" viewable />
                <flux:input type="password" wire:model="password_confirmation" :label="__('Confirm password')" viewable />
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
