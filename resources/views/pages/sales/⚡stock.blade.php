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
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('sales.stock.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function products(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return Product::query()
            ->where('is_active', true)
            ->where('type', ProductType::FinishedGoods->value)
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('product_name', 'like', "%{$term}%")
                ->orWhere('product_code', 'like', "%{$term}%")))
            ->orderByDesc('current_stock')
            ->orderBy('product_code')
            ->paginate(20);
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
        <flux:breadcrumbs.item :href="route('sales.dashboard')" wire:navigate>{{ __('Sales') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Stock') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 motion-safe:animate-fade-slide-up">
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Finished Goods Stock') }}</h1>
        <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">{{ __('What\'s available to offer customers right now, updated as deliveries and production are recorded.') }}</p>
    </div>

    <div class="mt-6 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search name or code...')" class="max-w-sm" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,gotoPage,previousPage,nextPage" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Code') }}</th>
                        <th class="py-2 px-3">{{ __('Product') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Available') }}</th>
                        <th class="py-2 px-3">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->products as $product)
                        @php($empty = bccomp((string) $product->current_stock, '0', 2) <= 0)
                        <tr wire:key="product-{{ $product->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                            <td class="py-2 px-3 font-data text-accent whitespace-nowrap">{{ $product->product_code }}</td>
                            <td class="py-2 px-3 text-zinc-800 dark:text-zinc-200">{{ $product->product_name }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap {{ $empty ? 'text-zinc-400 dark:text-zinc-500' : 'text-zinc-700 dark:text-zinc-300 font-medium' }}">{{ $this->formatQuantity((string) $product->current_stock) }} {{ $product->unit_of_measure }}</td>
                            <td class="py-2 px-3">
                                @if ($empty)
                                    <span class="inline-flex items-center rounded-full border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/5 px-2 py-0.5 text-[11px] font-medium text-zinc-500 dark:text-zinc-400">{{ __('Out of stock') }}</span>
                                @else
                                    <span class="inline-flex items-center rounded-full border border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 px-2 py-0.5 text-[11px] font-medium text-green-700 dark:text-green-400">{{ __('Available') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No finished goods match your search.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $this->products->links() }}</div>
    </div>
</section>
