<?php

use App\Models\PurchaseOrder;
use App\Support\Kpi\PurchasingKpis;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Raw Material Stock')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $sort = '';

    public function mount(): void
    {
        Gate::authorize('purchasing.stock.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingSort(): void
    {
        $this->resetPage();
    }

    protected function filtered(): Collection
    {
        $rows = PurchasingKpis::materialStockStatus();

        $term = mb_strtolower(trim($this->search));

        if ($term !== '') {
            $rows = $rows->filter(function (array $row) use ($term) {
                return str_contains(mb_strtolower($row['product']->product_name), $term)
                    || str_contains(mb_strtolower($row['product']->product_code), $term);
            })->values();
        }

        return match ($this->sort) {
            'stock_desc' => $rows->sortByDesc(fn (array $row) => (float) $row['current_stock'])->values(),
            'stock_asc' => $rows->sortBy(fn (array $row) => (float) $row['current_stock'])->values(),
            default => $rows,
        };
    }

    #[Computed]
    public function materials(): LengthAwarePaginator
    {
        $rows = $this->filtered();
        $perPage = 20;
        $page = $this->getPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    public function formatQty(string|float $value): string
    {
        $formatted = number_format((float) $value, 2, ',', '.');

        return str_ends_with($formatted, ',00') ? substr($formatted, 0, -3) : rtrim($formatted, '0');
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

@php($statusMeta = [
    'below' => ['label' => __('Below reorder point'), 'badge' => 'bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20'],
    'near' => ['label' => __('Nearing reorder point'), 'badge' => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20'],
    'ok' => ['label' => __('OK'), 'badge' => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20'],
])

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('purchasing.dashboard')" wire:navigate>{{ __('Purchasing') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Stock') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 motion-safe:animate-fade-slide-up">
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Raw Material Stock') }}</h1>
        <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">{{ __('Current stock vs. reorder point, right now. "Nearing" = within one safety-stock buffer of the trigger.') }}</p>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
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

    <div wire:loading.class="opacity-50" wire:target="search,sort,gotoPage,previousPage,nextPage" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
            @forelse ($this->materials as $row)
                @php($p = $row['product'])
                @php($meta = $statusMeta[$row['status']])
                <div wire:key="material-{{ $p->id }}" class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-4 flex flex-col gap-3">
                    <div class="min-w-0">
                        <p class="font-data text-[11px] text-accent">{{ $p->product_code }}</p>
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200 leading-snug truncate" title="{{ $p->product_name }}">{{ $p->product_name }}</p>
                    </div>
                    <div class="flex items-end justify-between gap-2">
                        <div>
                            <p class="text-[10px] uppercase tracking-wide text-zinc-400 dark:text-zinc-500">{{ __('In Stock') }}</p>
                            <p class="font-data text-lg font-semibold tabular-nums whitespace-nowrap {{ $row['status'] === 'below' ? 'text-red-600 dark:text-red-400' : 'text-zinc-900 dark:text-white' }}">{{ $this->formatQty($row['current_stock']) }} <span class="text-xs font-normal text-zinc-400 dark:text-zinc-500">{{ $p->unit_of_measure }}</span></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] uppercase tracking-wide text-zinc-400 dark:text-zinc-500">{{ __('Reorder point') }}</p>
                            <p class="font-data text-xs tabular-nums text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ $this->formatQty($row['reorder_point']) }} {{ $p->unit_of_measure }}</p>
                        </div>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $meta['badge'] }}">{{ $meta['label'] }}</span>
                        @if ($row['status'] === 'below')
                            @can('create', PurchaseOrder::class)
                                <a href="{{ route('purchasing.orders.create', $row['single_supplier_id'] ? ['supplier' => $row['single_supplier_id'], 'product' => $p->id] : []) }}" wire:navigate class="shrink-0 text-xs font-medium text-accent hover:underline underline-offset-2">{{ __('Create PO') }}</a>
                            @endcan
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-full py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active raw materials.') }}</div>
            @endforelse
        </div>
        <div class="mt-4">{{ $this->materials->links() }}</div>
    </div>
</section>
