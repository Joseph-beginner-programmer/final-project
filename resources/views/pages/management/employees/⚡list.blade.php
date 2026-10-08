<?php

use App\Actions\Employee\CreateEmployeeAction;
use App\Actions\Employee\UpdateEmployeeAction;
use App\DTO\Employee\EmployeeData;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
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

new #[Title('Employees')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    // form state, shared by the create and edit modal
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $hireDate = '';

    public string $employeeStatus = 'active';

    public function mount(): void
    {
        Gate::authorize('viewAny', Employee::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    protected function searchFilteredQuery(): Builder
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return Employee::query()
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('employee_code', 'like', "%{$term}%")));
    }

    #[Computed]
    public function employees(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->searchFilteredQuery()
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->orderBy('name')
            ->paginate(15);
    }

    #[Computed]
    public function statusCounts(): array
    {
        return $this->searchFilteredQuery()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();
    }

    public function openCreate(): void
    {
        Gate::authorize('create', Employee::class);

        $this->resetForm();
        $this->hireDate = now()->toDateString();
        $this->modal('employee-form')->show();
    }

    public function openEdit(int $id): void
    {
        $employee = Employee::findOrFail($id);
        Gate::authorize('update', $employee);

        $this->resetValidation();
        $this->editingId = $employee->id;
        $this->name = $employee->name;
        $this->hireDate = $employee->hire_date->toDateString();
        $this->employeeStatus = $employee->status->value;
        $this->modal('employee-form')->show();
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name', 'hireDate', 'employeeStatus');
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'hireDate' => ['required', 'date', 'before_or_equal:today'],
            'employeeStatus' => ['required', Rule::enum(EmployeeStatus::class)],
        ];
    }

    protected function messages(): array
    {
        return [
            'hireDate.before_or_equal' => __('Hire date cannot be in the future.'),
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => __('Name'),
            'hireDate' => __('Hire Date'),
            'employeeStatus' => __('Status'),
        ];
    }

    public function save(): void
    {
        $this->validate();

        $data = new EmployeeData(
            name: $this->name,
            hireDate: $this->hireDate,
            status: EmployeeStatus::from($this->employeeStatus),
        );

        if ($this->editingId) {
            $employee = Employee::findOrFail($this->editingId);
            Gate::authorize('update', $employee);
            app(UpdateEmployeeAction::class)->handle($employee, $data);
            Flux::toast(variant: 'success', text: __('Employee updated.'));
        } else {
            Gate::authorize('create', Employee::class);
            $employee = app(CreateEmployeeAction::class)->handle($data);
            Flux::toast(variant: 'success', text: __('Employee :code added.', ['code' => $employee->employee_code]));
        }

        $this->modal('employee-form')->close();
        $this->resetForm();
    }

    public function statusBadgeClasses(EmployeeStatus $status): string
    {
        return match ($status) {
            EmployeeStatus::Active => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
            EmployeeStatus::Inactive => 'bg-zinc-100 text-zinc-500 border-zinc-200 dark:bg-white/5 dark:text-zinc-500 dark:border-white/10',
        };
    }
}; ?>

<style>
    @keyframes fade-slide-up {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .motion-safe\:animate-fade-slide-up {
        animation: fade-slide-up .5s cubic-bezier(.16,1,.3,1) both;
    }
    @media (prefers-reduced-motion: reduce) {
        .motion-safe\:animate-fade-slide-up { animation: none; }
    }
</style>

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('executive.dashboard')" wire:navigate>{{ __('Management') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Employees') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ __('Employees') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>

        @can('create', Employee::class)
            <flux:button variant="primary" icon="user-plus" wire:click="openCreate" class="active:scale-[0.95]">
                {{ __('New Employee') }}
            </flux:button>
        @endcan
    </div>

    {{-- Status tabs --}}
    <div class="mt-6 flex items-center gap-1.5 overflow-x-auto pb-1 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <button type="button" wire:click="$set('status', '')"
            class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                {{ $status === '' ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
            {{ __('All') }}
            <span class="font-data tabular-nums {{ $status === '' ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ array_sum($this->statusCounts) }}</span>
        </button>
        @foreach (EmployeeStatus::cases() as $option)
            <button type="button" wire:click="$set('status', '{{ $option->value }}')"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                    {{ $status === $option->value ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
                {{ __($option->label()) }}
                <span class="font-data tabular-nums {{ $status === $option->value ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->statusCounts[$option->value] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    <div class="mt-4 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search name or code...')" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,status" class="transition-opacity duration-150 motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
        {{-- Desktop --}}
        <div class="mt-4 hidden md:block overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Code') }}</th>
                        <th class="py-2 px-3">{{ __('Name') }}</th>
                        <th class="py-2 px-3">{{ __('Hire Date') }}</th>
                        <th class="py-2 px-3">{{ __('Status') }}</th>
                        <th class="py-2 px-3 w-12"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->employees as $employee)
                        <tr wire:key="emp-{{ $employee->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                            <td class="py-2 px-3 font-data text-accent whitespace-nowrap">{{ $employee->employee_code }}</td>
                            <td class="py-2 px-3 text-zinc-800 dark:text-zinc-200">{{ $employee->name }}</td>
                            <td class="py-2 px-3 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $employee->hire_date->format('d M Y') }}</td>
                            <td class="py-2 px-3">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->statusBadgeClasses($employee->status) }}">{{ __($employee->status->label()) }}</span>
                            </td>
                            <td class="py-2 px-3 text-right">
                                @can('update', $employee)
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEdit({{ $employee->id }})" :aria-label="__('Edit :name', ['name' => $employee->name])" class="active:scale-[0.95]" />
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No employees match your filters.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile --}}
        <div class="mt-4 md:hidden rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
            @forelse ($this->employees as $employee)
                <button type="button" wire:key="emp-m-{{ $employee->id }}" wire:click="openEdit({{ $employee->id }})"
                    class="w-full text-left block px-4 py-3 hover:bg-zinc-50 dark:hover:bg-white/3 active:scale-[0.98] transition-[background-color,transform] cursor-pointer">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm text-zinc-800 dark:text-zinc-200">{{ $employee->name }}</span>
                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->statusBadgeClasses($employee->status) }}">{{ __($employee->status->label()) }}</span>
                    </div>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        <span class="font-data text-accent">{{ $employee->employee_code }}</span> &middot; {{ __('Joined') }} {{ $employee->hire_date->format('d M Y') }}
                    </p>
                </button>
            @empty
                <p class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No employees match your filters.') }}</p>
            @endforelse
        </div>
    </div>

    <div class="mt-4">
        {{ $this->employees->links() }}
    </div>

    {{-- Create / edit modal --}}
    <flux:modal name="employee-form" focusable class="max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit Employee') : __('New Employee') }}</flux:heading>
                <flux:subheading>
                    {{ $editingId ? __('Changes apply immediately. Existing work orders keep the labor rates they were planned with.') : __('An employee code is generated automatically. Accounting sets the hourly rate separately.') }}
                </flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Name')" />
            <div class="grid grid-cols-1 sm:grid-cols-2 items-start gap-4">
                <flux:input type="date" wire:model="hireDate" :label="__('Hire Date')" />
                <flux:select wire:model="employeeStatus" :label="__('Status')">
                    @foreach (EmployeeStatus::cases() as $option)
                        <option value="{{ $option->value }}">{{ __($option->label()) }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
