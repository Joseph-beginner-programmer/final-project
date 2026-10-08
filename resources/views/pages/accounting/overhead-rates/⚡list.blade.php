<?php

use App\Actions\Production\SetOverheadRateAction;
use App\Exceptions\InvalidRateEffectiveDateException;
use App\Models\OverheadRate;
use App\Models\WorkCenter;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Overhead Rates')] class extends Component {
    #[Locked]
    public ?int $workCenterId = null;

    public string $ratePerHour = '';

    public string $effectiveFrom = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', OverheadRate::class);
    }

    #[Computed]
    public function workCenters(): Collection
    {
        return WorkCenter::query()
            ->with(['overheadRates' => fn ($query) => $query->with('createdBy')->orderByDesc('effective_from')])
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function selectedWorkCenter(): ?WorkCenter
    {
        return $this->workCenterId ? $this->workCenters->firstWhere('id', $this->workCenterId) : null;
    }

    public function currentRate(WorkCenter $workCenter): ?OverheadRate
    {
        $today = now()->toDateString();

        return $workCenter->overheadRates->first(fn (OverheadRate $rate) => $rate->effective_from->toDateString() <= $today
            && ($rate->effective_to === null || $rate->effective_to->toDateString() >= $today));
    }

    public function upcomingRate(WorkCenter $workCenter): ?OverheadRate
    {
        return $workCenter->overheadRates->first(fn (OverheadRate $rate) => $rate->effective_from->isFuture());
    }

    public function openSetRate(int $id): void
    {
        Gate::authorize('create', OverheadRate::class);

        $this->resetValidation();
        $this->workCenterId = $id;
        $this->ratePerHour = '';
        $this->effectiveFrom = now()->toDateString();
        $this->modal('set-overhead-rate')->show();
    }

    public function openHistory(int $id): void
    {
        $this->workCenterId = $id;
        $this->modal('overhead-history')->show();
    }

    public function saveRate(): void
    {
        Gate::authorize('create', OverheadRate::class);

        $this->validate(
            [
                'ratePerHour' => ['required', 'numeric', 'gt:0'],
                'effectiveFrom' => ['required', 'date'],
            ],
            attributes: [
                'ratePerHour' => __('Rate per Machine Hour'),
                'effectiveFrom' => __('Effective From'),
            ],
        );

        $workCenter = WorkCenter::findOrFail($this->workCenterId);

        try {
            app(SetOverheadRateAction::class)->handle($workCenter, (string) $this->ratePerHour, $this->effectiveFrom, Auth::id());
        } catch (InvalidRateEffectiveDateException $e) {
            $this->addError('effectiveFrom', $e->userMessage());

            return;
        }

        unset($this->workCenters);
        $this->modal('set-overhead-rate')->close();

        Flux::toast(variant: 'success', text: __('Overhead rate for :center saved.', ['center' => __($workCenter->name)]));
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
        <flux:breadcrumbs.item>{{ __('Overhead Rates') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 motion-safe:animate-fade-slide-up">
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
            {{ __('Overhead Rates') }}
        </h1>
        <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5 max-w-2xl">
            {{ __('Applied overhead (electricity, machine depreciation) is charged to work orders per machine hour. Set each rate from the budget: estimated monthly overhead ÷ budgeted machine hours. Work orders keep the rate that applied when they were created.') }}
        </p>
    </div>

    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ($this->workCenters as $workCenter)
            @php($current = $this->currentRate($workCenter))
            @php($upcoming = $this->upcomingRate($workCenter))
            <div wire:key="wc-{{ $workCenter->id }}" class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 flex flex-col overflow-hidden motion-safe:animate-fade-slide-up" style="animation-delay: {{ 40 + $loop->index * 40 }}ms;">
                <div class="p-5 flex-1">
                    <p class="font-data text-[11px] uppercase tracking-wider text-accent">{{ $workCenter->code }}</p>
                    <h2 class="font-display text-lg font-bold text-zinc-900 dark:text-white">{{ __($workCenter->name) }}</h2>

                    <div class="mt-4">
                        <p class="text-[11px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Current Rate / Machine Hour') }}</p>
                        @if ($current)
                            <p class="mt-1 font-data text-2xl font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $current->rate_per_hour) }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('since') }} <span class="font-data tabular-nums">{{ $current->effective_from->format('d M Y') }}</span></p>
                        @else
                            <span class="mt-2 inline-flex items-center gap-1 rounded-full border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 px-2 py-0.5 text-xs text-amber-700 dark:text-amber-400">
                                <flux:icon.exclamation-triangle variant="micro" class="size-3" />
                                {{ __('Not set — work orders for this work center cannot be created yet.') }}
                            </span>
                        @endif

                        @if ($upcoming)
                            <p class="mt-2 font-data text-xs tabular-nums text-zinc-500 dark:text-zinc-400">
                                &rarr; {{ $this->formatRupiah((string) $upcoming->rate_per_hour) }} {{ __('from') }} {{ $upcoming->effective_from->format('d M Y') }}
                            </p>
                        @endif
                    </div>
                </div>

                <div class="px-5 py-3 border-t border-zinc-100 dark:border-white/10 flex justify-end gap-2">
                    <flux:button size="sm" variant="ghost" icon="clock" wire:click="openHistory({{ $workCenter->id }})" class="active:scale-[0.95]">{{ __('History') }}</flux:button>
                    @can('create', OverheadRate::class)
                        <flux:button size="sm" variant="filled" icon="banknotes" wire:click="openSetRate({{ $workCenter->id }})" class="active:scale-[0.95]">{{ __('Set Rate') }}</flux:button>
                    @endcan
                </div>
            </div>
        @endforeach
    </div>

    {{-- Set rate modal --}}
    <flux:modal name="set-overhead-rate" focusable class="max-w-md">
        <form wire:submit="saveRate" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Set Overhead Rate') }}</flux:heading>
                <flux:subheading>{{ $this->selectedWorkCenter ? __($this->selectedWorkCenter->name) : '' }}</flux:subheading>
            </div>

            @if ($this->selectedWorkCenter && ($current = $this->currentRate($this->selectedWorkCenter)))
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Current rate') }}: <span class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatRupiah((string) $current->rate_per_hour) }}</span>
                    {{ __('since') }} {{ $current->effective_from->format('d M Y') }}
                </p>
            @endif

            <div>
                <flux:input.group :label="__('Rate per Machine Hour')">
                    <flux:input.group.prefix>Rp</flux:input.group.prefix>
                    <flux:input wire:model="ratePerHour" type="text" inputmode="numeric" pattern="[0-9]*" input:class="text-right font-data tabular-nums" placeholder="0" />
                </flux:input.group>
                @error('ratePerHour') <flux:error class="mt-1" :message="$message" /> @enderror
            </div>

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
    <flux:modal name="overhead-history" class="max-w-lg">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Rate History') }}</flux:heading>
                <flux:subheading>{{ $this->selectedWorkCenter ? __($this->selectedWorkCenter->name) : '' }}</flux:subheading>
            </div>

            <div class="rounded-md border border-zinc-200 dark:border-white/10 divide-y divide-zinc-100 dark:divide-white/5 text-xs">
                @forelse ($this->selectedWorkCenter?->overheadRates ?? [] as $rate)
                    <div wire:key="oh-hist-{{ $rate->id }}" class="flex items-center justify-between gap-3 px-3 py-2.5">
                        <div>
                            <p class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">
                                {{ $rate->effective_from->format('d M Y') }} &ndash; {{ $rate->effective_to?->format('d M Y') ?? __('now') }}
                            </p>
                            <p class="text-zinc-400 dark:text-zinc-500 mt-0.5">{{ __('Set by') }} {{ $rate->createdBy->name }}</p>
                        </div>
                        <span class="font-data font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $rate->rate_per_hour) }}</span>
                    </div>
                @empty
                    <p class="px-3 py-6 text-center text-zinc-500 dark:text-zinc-400">{{ __('No rates set yet.') }}</p>
                @endforelse
            </div>
        </div>
    </flux:modal>
</section>
