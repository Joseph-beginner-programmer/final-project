<?php

use App\Actions\Employee\SetLaborRateAction;
use App\Exceptions\InvalidRateEffectiveDateException;
use App\Models\Employee;
use App\Models\LaborRate;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Labor Rates')] class extends Component {
    #[Url]
    public string $search = '';

    #[Locked]
    public ?int $employeeId = null;

    public string $hourlyRate = '';

    public string $effectiveFrom = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', LaborRate::class);
    }

    /**
     * Every employee (inactive ones too — their rate history is still part of old work orders' costs),
     * with full rate history eager-loaded newest-first.
     */
    #[Computed]
    public function employees(): Collection
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return Employee::query()
            ->with(['laborRates' => fn ($query) => $query->with('createdBy')->orderByDesc('effective_from')])
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$term}%")
                ->orWhere('employee_code', 'like', "%{$term}%")))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedEmployee(): ?Employee
    {
        return $this->employeeId ? $this->employees->firstWhere('id', $this->employeeId) : null;
    }

    public function currentRate(Employee $employee): ?LaborRate
    {
        $today = now()->toDateString();

        return $employee->laborRates->first(fn (LaborRate $rate) => $rate->effective_from->toDateString() <= $today
            && ($rate->effective_to === null || $rate->effective_to->toDateString() >= $today));
    }

    public function upcomingRate(Employee $employee): ?LaborRate
    {
        return $employee->laborRates->first(fn (LaborRate $rate) => $rate->effective_from->isFuture());
    }

    public function openSetRate(int $id): void
    {
        Gate::authorize('create', LaborRate::class);

        $this->resetValidation();
        $this->employeeId = $id;
        $this->hourlyRate = '';
        $this->effectiveFrom = now()->toDateString();
        $this->modal('set-rate')->show();
    }

    public function openHistory(int $id): void
    {
        $this->employeeId = $id;
        $this->modal('rate-history')->show();
    }

    public function saveRate(): void
    {
        Gate::authorize('create', LaborRate::class);

        $this->validate(
            [
                'hourlyRate' => ['required', 'numeric', 'gt:0'],
                'effectiveFrom' => ['required', 'date'],
            ],
            attributes: [
                'hourlyRate' => __('Hourly Rate'),
                'effectiveFrom' => __('Effective From'),
            ],
        );

        $employee = Employee::findOrFail($this->employeeId);

        try {
            app(SetLaborRateAction::class)->handle($employee, (string) $this->hourlyRate, $this->effectiveFrom, Auth::id());
        } catch (InvalidRateEffectiveDateException $e) {
            $this->addError('effectiveFrom', $e->userMessage());

            return;
        }

        unset($this->employees);
        $this->modal('set-rate')->close();

        Flux::toast(variant: 'success', text: __('Hourly rate for :name saved.', ['name' => $employee->name]));
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
        animation: fade-slide-up .5s cubic-bezier(.16,1,.3,1) both;
    }
    @media (prefers-reduced-motion: reduce) {
        .motion-safe\:animate-fade-slide-up { animation: none; }
    }
</style>

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('accounting.dashboard')" wire:navigate>{{ __('Accounting') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Labor Rates') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 motion-safe:animate-fade-slide-up">
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
            {{ __('Labor Rates') }}
        </h1>
        <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5 max-w-2xl">
            {{ __('A new rate never overwrites the old one — it starts on its effective date and the previous rate ends the day before. Work orders keep the rate that applied when they were created.') }}
        </p>
    </div>

    <div class="mt-6 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search name or code...')" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search" class="transition-opacity duration-150 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        {{-- Desktop --}}
        <div class="mt-4 hidden md:block overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Employee') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Current Rate / Hour') }}</th>
                        <th class="py-2 px-3">{{ __('Effective Since') }}</th>
                        <th class="py-2 px-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->employees as $employee)
                        @php($current = $this->currentRate($employee))
                        @php($upcoming = $this->upcomingRate($employee))
                        <tr wire:key="rate-{{ $employee->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                            <td class="py-2 px-3">
                                <p class="font-data text-accent">{{ $employee->employee_code }}</p>
                                <p class="text-zinc-800 dark:text-zinc-200">
                                    {{ $employee->name }}
                                    @if ($employee->status !== \App\Enums\EmployeeStatus::Active)
                                        <span class="ml-1 text-[11px] text-zinc-400 dark:text-zinc-500">({{ __('Inactive') }})</span>
                                    @endif
                                </p>
                            </td>
                            <td class="py-2 px-3 text-right whitespace-nowrap">
                                @if ($current)
                                    <span class="font-data font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $current->hourly_rate) }}</span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 px-2 py-0.5 text-[11px] text-amber-700 dark:text-amber-400">
                                        <flux:icon.exclamation-triangle variant="micro" class="size-3" />
                                        {{ __('Not set') }}
                                    </span>
                                @endif
                                @if ($upcoming)
                                    <p class="mt-0.5 font-data text-[11px] tabular-nums text-zinc-400 dark:text-zinc-500">
                                        &rarr; {{ $this->formatRupiah((string) $upcoming->hourly_rate) }} {{ __('from') }} {{ $upcoming->effective_from->format('d M Y') }}
                                    </p>
                                @endif
                            </td>
                            <td class="py-2 px-3 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $current?->effective_from->format('d M Y') ?? '—' }}</td>
                            <td class="py-2 px-3 text-right whitespace-nowrap">
                                <flux:button size="sm" variant="ghost" icon="clock" wire:click="openHistory({{ $employee->id }})" class="active:scale-[0.95]">{{ __('History') }}</flux:button>
                                @can('create', LaborRate::class)
                                    <flux:button size="sm" variant="filled" icon="banknotes" wire:click="openSetRate({{ $employee->id }})" class="active:scale-[0.95]">{{ __('Set Rate') }}</flux:button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No employees found.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile --}}
        <div class="mt-4 md:hidden rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
            @forelse ($this->employees as $employee)
                @php($current = $this->currentRate($employee))
                <div wire:key="rate-m-{{ $employee->id }}" class="px-4 py-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="text-sm text-zinc-800 dark:text-zinc-200">{{ $employee->name }}</p>
                            <p class="text-xs font-data text-accent">{{ $employee->employee_code }}</p>
                        </div>
                        <span class="font-data text-sm font-medium tabular-nums {{ $current ? 'text-zinc-900 dark:text-white' : 'text-amber-600 dark:text-amber-400' }}">
                            {{ $current ? $this->formatRupiah((string) $current->hourly_rate) : __('Not set') }}
                        </span>
                    </div>
                    <div class="mt-2 flex justify-end gap-2">
                        <flux:button size="xs" variant="ghost" icon="clock" wire:click="openHistory({{ $employee->id }})">{{ __('History') }}</flux:button>
                        @can('create', LaborRate::class)
                            <flux:button size="xs" variant="filled" icon="banknotes" wire:click="openSetRate({{ $employee->id }})">{{ __('Set Rate') }}</flux:button>
                        @endcan
                    </div>
                </div>
            @empty
                <p class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No employees found.') }}</p>
            @endforelse
        </div>
    </div>

    {{-- Set rate modal --}}
    <flux:modal name="set-rate" focusable class="max-w-md">
        <form wire:submit="saveRate" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Set Hourly Rate') }}</flux:heading>
                <flux:subheading>{{ $this->selectedEmployee?->name }} &middot; <span class="font-data">{{ $this->selectedEmployee?->employee_code }}</span></flux:subheading>
            </div>

            @if ($this->selectedEmployee && ($current = $this->currentRate($this->selectedEmployee)))
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Current rate') }}: <span class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatRupiah((string) $current->hourly_rate) }}</span>
                    {{ __('since') }} {{ $current->effective_from->format('d M Y') }}
                </p>
            @endif

            <flux:input.group :label="__('Hourly Rate')">
                <flux:input.group.prefix>Rp</flux:input.group.prefix>
                <flux:input wire:model="hourlyRate" type="text" inputmode="numeric" pattern="[0-9]*" input:class="text-right font-data tabular-nums" placeholder="0" />
            </flux:input.group>
            @error('hourlyRate') <flux:error class="-mt-3" :message="$message" /> @enderror

            <flux:input type="date" wire:model="effectiveFrom" :label="__('Effective From')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="saveRate" class="active:scale-[0.95]">
                    {{ __('Save Rate') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- History modal --}}
    <flux:modal name="rate-history" class="max-w-lg">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Rate History') }}</flux:heading>
                <flux:subheading>{{ $this->selectedEmployee?->name }}</flux:subheading>
            </div>

            <div class="rounded-md border border-zinc-200 dark:border-white/10 divide-y divide-zinc-100 dark:divide-white/5 text-xs">
                @forelse ($this->selectedEmployee?->laborRates ?? [] as $rate)
                    <div wire:key="hist-{{ $rate->id }}" class="flex items-center justify-between gap-3 px-3 py-2.5">
                        <div>
                            <p class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">
                                {{ $rate->effective_from->format('d M Y') }} &ndash; {{ $rate->effective_to?->format('d M Y') ?? __('now') }}
                            </p>
                            <p class="text-zinc-400 dark:text-zinc-500 mt-0.5">{{ __('Set by') }} {{ $rate->createdBy->name }}</p>
                        </div>
                        <span class="font-data font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $rate->hourly_rate) }}</span>
                    </div>
                @empty
                    <p class="px-3 py-6 text-center text-zinc-500 dark:text-zinc-400">{{ __('No rates set yet.') }}</p>
                @endforelse
            </div>
        </div>
    </flux:modal>
</section>
