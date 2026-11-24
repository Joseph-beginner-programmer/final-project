<?php

use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Stock')] class extends Component {
    use WithPagination;

    #[Url]
    public string $type = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $sort = '';

    public function mount(): void
    {
        Gate::authorize('management.stock.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingSort(): void
    {
        $this->resetPage();
    }

    protected function filtered(): Builder
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return Product::query()
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('product_name', 'like', "%{$term}%")
                ->orWhere('product_code', 'like', "%{$term}%")));
    }

    #[Computed]
    public function products(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = $this->filtered()
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type));

        match ($this->sort) {
            'stock_desc' => $query->orderByDesc('current_stock'),
            'stock_asc' => $query->orderBy('current_stock'),
            default => $query->orderBy('type'),
        };

        return $query->orderBy('product_code')->paginate(20);
    }

    #[Computed]
    public function typeCounts(): array
    {
        return $this->filtered()->selectRaw('type, count(*) as count')->groupBy('type')->pluck('count', 'type')->all();
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
        <flux:breadcrumbs.item :href="route('executive.dashboard')" wire:navigate>{{ __('Management') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Stock') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 motion-safe:animate-fade-slide-up">
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Stock') }}</h1>
        <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">{{ __('Updates automatically as receipts, deliveries, and production are recorded — no manual refresh needed.') }}</p>
    </div>

    <div class="mt-6 flex items-center gap-1.5 overflow-x-auto pb-1 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <button type="button" wire:click="$set('type', '')"
            class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                {{ $type === '' ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
            {{ __('All') }}
            <span class="font-data tabular-nums {{ $type === '' ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ array_sum($this->typeCounts) }}</span>
        </button>
        @foreach (ProductType::cases() as $option)
            <button type="button" wire:click="$set('type', '{{ $option->value }}')"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                    {{ $type === $option->value ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
                {{ $option->label() }}
                <span class="font-data tabular-nums {{ $type === $option->value ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->typeCounts[$option->value] ?? 0 }}</span>
            </button>
        @endforeach
    </div>
    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    <div class="mt-4 flex flex-wrap items-center gap-3 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <div class="flex-1 min-w-48">
            <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search name or code...')" />
        </div>
        <div class="w-48 sm:w-64">
            <flux:select size="sm" wire:model.live="sort" :placeholder="__('Sort by')">
                <option value="stock_desc">{{ __('Highest stock first') }}</option>
                <option value="stock_asc">{{ __('Lowest stock first') }}</option>
            </flux:select>
        </div>
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,type,sort,gotoPage,previousPage,nextPage" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
            @forelse ($this->products as $product)
                @php($low = $product->isBelowReorderPoint() && bccomp((string) $product->reorder_point, '0', 2) > 0)
                <div wire:key="product-{{ $product->id }}" class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-4 flex flex-col gap-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-data text-[11px] text-accent">{{ $product->product_code }}</p>
                            <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200 leading-snug truncate" title="{{ $product->product_name }}">{{ $product->product_name }}</p>
                        </div>
                        <span class="shrink-0 inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap {{ $product->type->badgeClasses() }}">{{ $product->type->label() }}</span>
                    </div>
                    <div class="flex items-end justify-between gap-2">
                        <div>
                            <p class="text-[10px] uppercase tracking-wide text-zinc-400 dark:text-zinc-500">{{ __('In Stock') }}</p>
                            <p class="font-data text-lg font-semibold tabular-nums whitespace-nowrap {{ $low ? 'text-red-600 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">{{ $this->formatQuantity((string) $product->current_stock) }} <span class="text-xs font-normal text-zinc-400 dark:text-zinc-500">{{ $product->unit_of_measure }}</span></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] uppercase tracking-wide text-zinc-400 dark:text-zinc-500">{{ __('Reorder point') }}</p>
                            <p class="font-data text-xs tabular-nums text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ $this->formatQuantity((string) $product->reorder_point) }} {{ $product->unit_of_measure }}</p>
                        </div>
                    </div>
                    <div>
                        @if ($low)
                            <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 text-[11px] font-medium text-red-700 dark:text-red-400">
                                <flux:icon.exclamation-triangle variant="micro" class="size-3" />{{ __('Below reorder point') }}
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-full border border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 px-2 py-0.5 text-[11px] font-medium text-green-700 dark:text-green-400">{{ __('OK') }}</span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-full py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No products match your filters.') }}</div>
            @endforelse
        </div>
        <div class="mt-4">{{ $this->products->links() }}</div>
    </div>
</section>
