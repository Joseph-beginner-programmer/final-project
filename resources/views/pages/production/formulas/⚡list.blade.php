<?php

use App\Models\ProductionFormula;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Production Formulas')] class extends Component {
    #[Url]
    public string $search = '';

    #[Url]
    public bool $showInactive = false;

    public function mount(): void
    {
        Gate::authorize('viewAny', ProductionFormula::class);
    }

    #[Computed]
    public function formulas(): \Illuminate\Support\Collection
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return ProductionFormula::query()
            ->when(! $this->showInactive, fn (Builder $q) => $q->where('is_active', true))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('formula_code', 'like', "%{$term}%")
                ->orWhereHas('product', fn (Builder $q) => $q->where('product_name', 'like', "%{$term}%"))))
            ->with(['product', 'workCenter', 'items.materialProduct'])
            ->withCount('workOrders')
            ->orderBy('formula_code')
            ->get();
    }

    public function formatQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
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
        <flux:breadcrumbs.item :href="route('production.dashboard')" wire:navigate>{{ __('Production') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Production Formulas') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Production Formulas') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>
        @can('create', ProductionFormula::class)
            <flux:button variant="primary" icon="plus" :href="route('production.formulas.create')" wire:navigate class="active:scale-[0.95]">{{ __('New Formula') }}</flux:button>
        @endcan
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="flex-1 min-w-56">
            <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search formula or product...')" />
        </div>
        <flux:switch wire:model.live="showInactive" :label="__('Show inactive')" align="left" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,showInactive" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        @forelse ($this->formulas as $formula)
            <a wire:key="formula-{{ $formula->id }}" href="{{ route('production.formulas.edit', $formula) }}" wire:navigate
                class="group block rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-5 transition-[transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:shadow-xl focus-visible:outline-2 focus-visible:outline-accent {{ $formula->is_active ? '' : 'opacity-60' }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-data text-xs text-accent">{{ $formula->formula_code }}</p>
                        <p class="mt-0.5 font-display text-base font-bold text-zinc-900 dark:text-white">{{ $formula->product->product_name }}</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __($formula->workCenter->name) }} · {{ __('per') }} <span class="font-data tabular-nums">{{ $this->formatQuantity((string) $formula->output_quantity) }} {{ $formula->product->unit_of_measure }}</span>
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $formula->product->type->badgeClasses() }}">{{ $formula->product->type->label() }}</span>
                        @unless ($formula->is_active)
                            <span class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Inactive') }}</span>
                        @endunless
                    </div>
                </div>
                <ul class="mt-4 pt-3 border-t border-zinc-100 dark:border-white/10 space-y-1 text-xs">
                    @foreach ($formula->items as $item)
                        <li class="flex items-center justify-between gap-3">
                            <span class="text-zinc-700 dark:text-zinc-300">{{ $item->materialProduct->product_name }}</span>
                            <span class="font-data tabular-nums text-zinc-500 dark:text-zinc-400">{{ $this->formatQuantity((string) $item->quantity) }} {{ $item->materialProduct->unit_of_measure }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-[11px] text-zinc-500 dark:text-zinc-400">{{ trans_choice('Used by :count work order|Used by :count work orders', $formula->work_orders_count) }}</p>
            </a>
        @empty
            <p class="md:col-span-2 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No formulas match your filters.') }}</p>
        @endforelse
    </div>
</section>
