<?php

use App\Actions\Production\SetOverheadRateAction;
use App\Enums\OverheadCategory;
use App\Exceptions\InvalidOverheadEstimateException;
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

    /** @var array<string, string> */
    public array $amounts = [];

    public string $budgetedHours = '';

    public string $effectiveFrom = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', OverheadRate::class);
    }

    #[Computed]
    public function workCenters(): Collection
    {
        return WorkCenter::query()
            ->with(['overheadRates' => fn ($query) => $query->with(['createdBy', 'components'])->orderByDesc('effective_from')])
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

    #[Computed]
    public function previewTotal(): string
    {
        return array_reduce($this->amounts, fn (string $carry, $amount) => is_numeric($amount) && $amount >= 0 ? bcadd($carry, (string) $amount, 2) : $carry, '0');
    }

    #[Computed]
    public function previewRate(): ?string
    {
        if (! is_numeric($this->budgetedHours) || bccomp($this->budgetedHours, '0', 2) <= 0 || bccomp($this->previewTotal, '0', 2) <= 0) {
            return null;
        }

        return SetOverheadRateAction::rateFor($this->previewTotal, $this->budgetedHours);
    }

    public function openSetRate(int $id): void
    {
        Gate::authorize('create', OverheadRate::class);

        $this->resetValidation();
        $this->workCenterId = $id;
        $this->effectiveFrom = now()->toDateString();
        $this->amounts = collect($this->selectedWorkCenter->overheadCategories())->mapWithKeys(fn (OverheadCategory $c) => [$c->value => ''])->all();
        $this->budgetedHours = '';

        $current = $this->currentRate($this->selectedWorkCenter);
        if ($current?->hasBreakdown()) {
            foreach ($current->components as $component) {
                $this->amounts[$component->category->value] = bcadd((string) $component->amount, '0', 0);
            }
            $this->budgetedHours = $this->formatQuantity((string) $current->budgeted_machine_hours);
        }

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
                'amounts.*' => ['nullable', 'numeric', 'min:0'],
                'budgetedHours' => ['required', 'numeric', 'gt:0'],
                'effectiveFrom' => ['required', 'date'],
            ],
            attributes: [
                'amounts.*' => __('Amount'),
                'budgetedHours' => __('Budgeted machine hours'),
                'effectiveFrom' => __('Effective From'),
            ],
        );

        $workCenter = WorkCenter::findOrFail($this->workCenterId);

        try {
            app(SetOverheadRateAction::class)->handle($workCenter, $this->amounts, (string) $this->budgetedHours, $this->effectiveFrom, Auth::id());
        } catch (InvalidOverheadEstimateException $e) {
            $this->addError('amounts', $e->userMessage());

            return;
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

    public function formatQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    public function share(string $amount, string $total): int
    {
        return bccomp($total, '0', 2) > 0 ? (int) round((float) bcdiv(bcmul($amount, '100', 4), $total, 4)) : 0;
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
            {{ __('Factory overhead (electricity, machine and mold depreciation, maintenance, other) is charged to work orders per machine hour. The rate is the estimated monthly overhead of a work center divided by its budgeted machine hours. Work orders keep the rate that applied when they were created.') }}
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

                    @if ($current?->hasBreakdown())
                        @php($total = $current->budgetedTotal())
                        <div class="mt-4 rounded-lg bg-zinc-50 dark:bg-white/5 p-3">
                            <p class="flex flex-wrap items-baseline gap-x-1.5 font-data text-xs tabular-nums text-zinc-600 dark:text-zinc-300">
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->formatRupiah($total) }}</span>
                                <span>÷</span>
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $current->budgeted_machine_hours) }} {{ __('machine hrs') }}</span>
                                <span class="text-zinc-400">{{ __('per month') }}</span>
                            </p>
                            <ul class="mt-2.5 space-y-1.5">
                                @foreach ($workCenter->overheadCategories() as $category)
                                    @php($component = $current->components->firstWhere('category', $category))
                                    @php($amount = $component ? (string) $component->amount : '0')
                                    @php($pct = $this->share($amount, $total))
                                    <li class="text-xs {{ $component ? '' : 'opacity-50' }}">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="text-zinc-600 dark:text-zinc-300">{{ $category->label() }}</span>
                                            <span class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $component ? $this->formatRupiah($amount) : '—' }} <span class="inline-block w-9 text-right text-zinc-400">{{ $pct }}%</span></span>
                                        </div>
                                        <div class="mt-1 h-1 rounded-full bg-zinc-200 dark:bg-white/10" role="presentation">
                                            <div class="h-1 rounded-full bg-accent" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @elseif ($current)
                        <p class="mt-4 rounded-lg border border-dashed border-zinc-200 dark:border-white/10 px-3 py-2 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('Entered as a single number, before rates had a breakdown. Set a new rate to record its calculation.') }}
                        </p>
                    @endif
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

    <flux:modal name="set-overhead-rate" focusable class="w-full max-w-lg">
        <form wire:submit="saveRate" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Set Overhead Rate') }}</flux:heading>
                <flux:subheading>{{ $this->selectedWorkCenter ? __($this->selectedWorkCenter->name) : '' }} · {{ __('estimate for one month') }}</flux:subheading>
            </div>

            <fieldset class="space-y-3">
                <legend class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Estimated monthly overhead') }}</legend>
                @foreach ($this->selectedWorkCenter?->overheadCategories() ?? [] as $category)
                    <div wire:key="amount-{{ $category->value }}" class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_11rem] items-center gap-x-3 gap-y-1">
                        <div>
                            <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $category->label() }}</p>
                            <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $category->hint() }}</p>
                        </div>
                        <flux:input.group>
                            <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                            <flux:input size="sm" wire:model.live.debounce.400ms="amounts.{{ $category->value }}" type="text" inputmode="numeric" pattern="[0-9]*" input:class="text-right font-data tabular-nums" placeholder="0" :loading="false" :aria-label="$category->label()" />
                        </flux:input.group>
                        @error('amounts.'.$category->value) <flux:error class="sm:col-start-2" :message="$message" /> @enderror
                    </div>
                @endforeach
                @error('amounts') <flux:error :message="$message" /> @enderror
            </fieldset>

            <div class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_11rem] items-start gap-x-3 gap-y-1">
                <div>
                    <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ __('Budgeted machine hours') }}</p>
                    <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('How long the machines are expected to run in a month, e.g. 9 machines × 8 hrs × 25 days = 1,800') }}</p>
                </div>
                <div>
                    <flux:input.group>
                        <flux:input size="sm" wire:model.live.debounce.400ms="budgetedHours" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-data tabular-nums" placeholder="0" :loading="false" :aria-label="__('Budgeted machine hours')" />
                        <flux:input.group.suffix class="px-2 text-xs">{{ __('hrs') }}</flux:input.group.suffix>
                    </flux:input.group>
                    @error('budgetedHours') <flux:error class="mt-1" :message="$message" /> @enderror
                </div>
            </div>

            <div class="rounded-lg bg-accent px-4 py-3 text-accent-foreground" aria-live="polite">
                <p class="text-[10px] font-semibold uppercase tracking-widest text-accent-foreground/70">{{ __('Rate per Machine Hour') }}</p>
                <p class="mt-0.5 font-data text-xl font-medium tabular-nums">{{ $this->previewRate !== null ? $this->formatRupiah($this->previewRate) : '—' }}</p>
                <p class="mt-1 font-data text-[11px] tabular-nums text-accent-foreground/80">
                    {{ $this->formatRupiah($this->previewTotal) }} ÷ {{ is_numeric($budgetedHours) ? $this->formatQuantity((string) $budgetedHours) : '0' }} {{ __('machine hrs') }}
                </p>
            </div>

            @if ($this->selectedWorkCenter && ($current = $this->currentRate($this->selectedWorkCenter)))
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('Current rate') }}: <span class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatRupiah((string) $current->rate_per_hour) }}</span>
                    {{ __('since') }} {{ $current->effective_from->format('d M Y') }}
                </p>
            @endif

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

    <flux:modal name="overhead-history" class="w-full max-w-lg">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Rate History') }}</flux:heading>
                <flux:subheading>{{ $this->selectedWorkCenter ? __($this->selectedWorkCenter->name) : '' }}</flux:subheading>
            </div>

            <div class="rounded-md border border-zinc-200 dark:border-white/10 divide-y divide-zinc-100 dark:divide-white/5 text-xs">
                @forelse ($this->selectedWorkCenter?->overheadRates ?? [] as $rate)
                    <div wire:key="oh-hist-{{ $rate->id }}" class="flex items-start justify-between gap-3 px-3 py-2.5">
                        <div class="min-w-0">
                            <p class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">
                                {{ $rate->effective_from->format('d M Y') }} &ndash; {{ $rate->effective_to?->format('d M Y') ?? __('now') }}
                            </p>
                            <p class="text-zinc-400 dark:text-zinc-500 mt-0.5">{{ __('Set by') }} {{ $rate->createdBy->name }}</p>
                            @if ($rate->hasBreakdown())
                                <p class="mt-1 text-zinc-500 dark:text-zinc-400">
                                    {{ $rate->components->map(fn ($c) => $c->category->label().' '.$this->formatRupiah((string) $c->amount))->implode(' · ') }}
                                </p>
                            @endif
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="font-data font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $rate->rate_per_hour) }}</p>
                            @if ($rate->hasBreakdown())
                                <p class="font-data tabular-nums text-zinc-500 dark:text-zinc-400">{{ $this->formatRupiah($rate->budgetedTotal()) }} ÷ {{ $this->formatQuantity((string) $rate->budgeted_machine_hours) }} {{ __('hrs') }}</p>
                            @else
                                <p class="text-zinc-400 dark:text-zinc-500">{{ __('no breakdown') }}</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="px-3 py-6 text-center text-zinc-500 dark:text-zinc-400">{{ __('No rates set yet.') }}</p>
                @endforelse
            </div>
        </div>
    </flux:modal>
</section>
