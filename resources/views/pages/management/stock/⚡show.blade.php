<?php

use App\Models\InventoryLot;
use App\Models\Product;
use App\Support\ProductCost;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Stock')] class extends Component {
    public Product $product;

    public function mount(): void
    {
        Gate::authorize('management.stock.view');
    }

    #[Computed]
    public function lots(): \Illuminate\Support\Collection
    {
        return InventoryLot::availableFifo($this->product->id)->get();
    }

    #[Computed]
    public function cost(): ?array
    {
        return ProductCost::forProducts([$this->product->id])[$this->product->id] ?? null;
    }

    #[Computed]
    public function isBelow(): bool
    {
        return $this->product->isBelowReorderPoint() && bccomp((string) $this->product->reorder_point, '0', 2) > 0;
    }

    public function formatQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    public function formatRupiah(string|float $amount): string
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
        animation: fade-slide-up .5s cubic-bezier(.16,1,.3,1) backwards;
    }
    @media (prefers-reduced-motion: reduce) {
        .motion-safe\:animate-fade-slide-up { animation: none; }
    }
</style>

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('executive.dashboard')" wire:navigate>{{ __('Management') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('management.stock.list')" wire:navigate>{{ __('Stock') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $product->product_name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <p class="font-data text-xs text-accent">{{ $product->product_code }}</p>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $product->product_name }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium whitespace-nowrap {{ $product->type->badgeClasses() }}">{{ $product->type->label() }}</span>
            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium {{ $product->is_active ? 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20' : 'bg-zinc-100 text-zinc-500 border-zinc-200 dark:bg-white/5 dark:text-zinc-500 dark:border-white/10' }}">{{ $product->is_active ? __('Active') : __('Inactive') }}</span>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 divide-x divide-y sm:divide-y-0 divide-zinc-100 dark:divide-white/5 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="px-4 sm:px-5 py-4">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('In Stock') }}</p>
            <p class="mt-1.5 font-display text-xl font-semibold {{ $this->isBelow ? 'text-red-700 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">{{ $this->formatQuantity((string) $product->current_stock) }} <span class="text-sm font-normal text-zinc-400">{{ $product->unit_of_measure }}</span></p>
        </div>
        <div class="px-4 sm:px-5 py-4">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Safety stock') }}</p>
            <p class="mt-1.5 font-display text-xl font-semibold text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $product->safety_stock) }} <span class="text-sm font-normal text-zinc-400">{{ $product->unit_of_measure }}</span></p>
        </div>
        <div class="px-4 sm:px-5 py-4">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Reorder point') }}</p>
            <p class="mt-1.5 font-display text-xl font-semibold text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $product->reorder_point) }} <span class="text-sm font-normal text-zinc-400">{{ $product->unit_of_measure }}</span></p>
        </div>
        <div class="px-4 sm:px-5 py-4">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</p>
            <p class="mt-1.5">
                @if ($this->isBelow)
                    <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 text-[11px] font-medium text-red-700 dark:text-red-400">
                        <flux:icon.exclamation-triangle variant="micro" class="size-3" />{{ __('Below reorder point') }}
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full border border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 px-2 py-0.5 text-[11px] font-medium text-green-700 dark:text-green-400">{{ __('OK') }}</span>
                @endif
            </p>
        </div>
    </div>

    @can('costs.view')
        @php($cost = $this->cost)
        @php($costValue = $cost['average'] ?? $cost['last'] ?? null)
        <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 divide-x divide-y sm:divide-y-0 divide-zinc-100 dark:divide-white/5 motion-safe:animate-fade-slide-up" style="animation-delay: 60ms;">
            <div class="px-4 sm:px-5 py-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Average cost') }}</p>
                @if ($costValue !== null)
                    <p class="mt-1.5 font-display text-xl font-semibold text-zinc-900 dark:text-white">{{ $this->formatRupiah($costValue) }}</p>
                    @if ($cost['average'] === null)
                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('last lot, no stock') }}</p>
                    @endif
                @else
                    <p class="mt-1.5 font-display text-xl font-semibold text-zinc-400 dark:text-zinc-500">—</p>
                @endif
            </div>
            <div class="px-4 sm:px-5 py-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Selling Price') }}</p>
                <p class="mt-1.5 font-display text-xl font-semibold text-zinc-900 dark:text-white">{{ $product->selling_price !== null ? $this->formatRupiah((string) $product->selling_price) : '—' }}</p>
            </div>
            <div class="px-4 sm:px-5 py-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('margin') }}</p>
                @if ($product->selling_price !== null && $costValue !== null)
                    @php($margin = ProductCost::margin((string) $product->selling_price, $costValue))
                    <p @class(['mt-1.5 font-display text-xl font-semibold', 'text-red-600 dark:text-red-400' => bccomp($margin, '0', 1) < 0, 'text-zinc-900 dark:text-white' => bccomp($margin, '0', 1) >= 0])>{{ str_replace('.', ',', $margin) }}%</p>
                @else
                    <p class="mt-1.5 font-display text-xl font-semibold text-zinc-400 dark:text-zinc-500">—</p>
                @endif
            </div>
        </div>
    @endcan

    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 80ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Lots') }}</h2>
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">{{ __('Oldest first — drawn in this order (FIFO) the next time stock moves out.') }}</p>

        <div class="mt-3 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Lot') }}</th>
                        <th class="py-2 px-3">{{ __('Received') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Remaining') }}</th>
                        @can('costs.view')
                            <th class="py-2 px-3 text-right">{{ __('Unit cost') }}</th>
                            <th class="py-2 px-3 text-right">{{ __('Value') }}</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->lots as $lot)
                        <tr wire:key="lot-{{ $lot->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                            <td class="py-2.5 px-3 font-data text-zinc-800 dark:text-zinc-200 whitespace-nowrap">{{ $lot->lot_number }}</td>
                            <td class="py-2.5 px-3 whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $lot->received_at->translatedFormat('d M Y') }}</td>
                            <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap font-medium text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $lot->quantity_remaining) }} {{ $product->unit_of_measure }}</td>
                            @can('costs.view')
                                <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $this->formatRupiah($lot->unit_cost) }}</td>
                                <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $this->formatRupiah($lot->value_remaining) }}</td>
                            @endcan
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No stock on hand.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
