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

    public function mount(): void
    {
        Gate::authorize('warehouse.stock.view');
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

        return Product::query()
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('product_name', 'like', "%{$term}%")
                ->orWhere('product_code', 'like', "%{$term}%")));
    }

    #[Computed]
    public function products(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->filtered()
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
            ->orderBy('type')
            ->orderBy('product_code')
            ->paginate(20);
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
        <flux:breadcrumbs.item>{{ __('Warehouse') }}</flux:breadcrumbs.item>
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

    <div class="mt-4 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search name or code...')" class="max-w-sm" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,type,gotoPage,previousPage,nextPage" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
        <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Code') }}</th>
                        <th class="py-2 px-3">{{ __('Product') }}</th>
                        <th class="py-2 px-3">{{ __('Type') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('In Stock') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Reorder point') }}</th>
                        <th class="py-2 px-3">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->products as $product)
                        @php($low = $product->isBelowReorderPoint() && bccomp((string) $product->reorder_point, '0', 2) > 0)
                        <tr wire:key="product-{{ $product->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                            <td class="py-2 px-3 font-data text-accent whitespace-nowrap">{{ $product->product_code }}</td>
                            <td class="py-2 px-3 text-zinc-800 dark:text-zinc-200">{{ $product->product_name }}</td>
                            <td class="py-2 px-3"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap {{ $product->type->badgeClasses() }}">{{ $product->type->label() }}</span></td>
                            <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap {{ $low ? 'text-red-600 dark:text-red-400 font-medium' : 'text-zinc-700 dark:text-zinc-300' }}">{{ $this->formatQuantity((string) $product->current_stock) }} {{ $product->unit_of_measure }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ $this->formatQuantity((string) $product->reorder_point) }} {{ $product->unit_of_measure }}</td>
                            <td class="py-2 px-3">
                                @if ($low)
                                    <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 text-[11px] font-medium text-red-700 dark:text-red-400">
                                        <flux:icon.exclamation-triangle variant="micro" class="size-3" />{{ __('Below reorder point') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full border border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 px-2 py-0.5 text-[11px] font-medium text-green-700 dark:text-green-400">{{ __('OK') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No products match your filters.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $this->products->links() }}</div>
    </div>
</section>
