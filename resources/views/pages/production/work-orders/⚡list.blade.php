<?php

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Work Orders')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $sort = 'planned_start_desc';

    public function mount(): void
    {
        Gate::authorize('viewAny', WorkOrder::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    #[Computed]
    public function statusOptions(): array
    {
        return WorkOrderStatus::cases();
    }

    protected function searchFilteredQuery(): Builder
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return WorkOrder::query()
            ->when($term !== '', function (Builder $query) use ($term) {
                $query->where(function (Builder $query) use ($term) {
                    $query->where('wo_number', 'like', "%{$term}%")
                        ->orWhereHas('product', function (Builder $query) use ($term) {
                            $query->where('product_name', 'like', "%{$term}%")
                                ->orWhere('product_code', 'like', "%{$term}%");
                        });
                });
            });
    }

    protected function filteredQuery(): Builder
    {
        return $this->searchFilteredQuery()
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status));
    }

    #[Computed]
    public function workOrders(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->with(['product', 'productionFormula', 'createdBy'])
            ->orderBy('planned_start_date', $this->sort === 'planned_start_asc' ? 'asc' : 'desc')
            ->orderBy('id', 'desc')
            ->paginate(10);
    }

    /**
     * Counts per status for the filter tabs — scoped to the search term only (not the
     * selected status), so every tab shows what it would contain if clicked.
     */
    #[Computed]
    public function statusCounts(): array
    {
        return $this->searchFilteredQuery()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();
    }

    #[Computed]
    public function totalMatchingSearch(): int
    {
        return array_sum($this->statusCounts);
    }

    public function formatQuantity(string $quantity): string
    {
        if (! str_contains($quantity, '.')) {
            return $quantity;
        }

        return rtrim(rtrim($quantity, '0'), '.');
    }

    public function statusBadgeClasses(WorkOrderStatus $status): string
    {
        return match ($status) {
            WorkOrderStatus::Draft => 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:border-white/10',
            WorkOrderStatus::Released => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-400 dark:border-blue-500/20',
            WorkOrderStatus::InProgress => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20',
            WorkOrderStatus::Completed => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
            WorkOrderStatus::Cancelled => 'bg-zinc-100 text-zinc-500 border-zinc-200 line-through dark:bg-white/5 dark:text-zinc-500 dark:border-white/10',
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
        <flux:breadcrumbs.item :href="route('production.dashboard')" wire:navigate>{{ __('Production') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Work Orders') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ __('Work Orders') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>

        @can('create', WorkOrder::class)
            <flux:button variant="primary" icon="document-plus" :href="route('production.work-orders.create')" wire:navigate class="active:scale-[0.95]">
                {{ __('New Work Order') }}
            </flux:button>
        @endcan
    </div>

    {{-- Status tabs --}}
    <div class="mt-6 flex items-center gap-1.5 overflow-x-auto pb-1 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <button type="button" wire:click="$set('status', '')" wire:loading.attr="disabled"
            class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                {{ $status === '' ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
            {{ __('All') }}
            <span class="font-data tabular-nums {{ $status === '' ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->totalMatchingSearch }}</span>
        </button>

        @foreach ($this->statusOptions as $option)
            <button type="button" wire:click="$set('status', '{{ $option->value }}')" wire:loading.attr="disabled"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                    {{ $status === $option->value ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
                {{ $option->label() }}
                <span class="font-data tabular-nums {{ $status === $option->value ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->statusCounts[$option->value] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    {{-- Toolbar --}}
    <div class="mt-4 flex flex-wrap items-end gap-3 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <div class="flex-1 min-w-48">
            <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search WO number or product...')" />
        </div>

        <div class="w-44">
            <flux:select size="sm" wire:model.live="sort">
                <option value="planned_start_desc">{{ __('Newest first') }}</option>
                <option value="planned_start_asc">{{ __('Oldest first') }}</option>
            </flux:select>
        </div>

        @if ($search !== '' || $status !== '')
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters" class="active:scale-[0.95]">
                {{ __('Clear filters') }}
            </flux:button>
        @endif

        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="$refresh" wire:loading.attr="disabled" class="ml-auto active:scale-[0.95]">
            {{ __('Refresh') }}
        </flux:button>
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,status,sort" class="transition-opacity duration-150 motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
        <div wire:loading wire:target="search,status,sort" class="mt-3 flex items-center gap-1.5 text-xs text-accent">
            <flux:icon.loading class="size-3.5" />
            {{ __('Updating...') }}
        </div>

        {{-- Desktop Table --}}
        <div class="mt-4 hidden lg:block overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead class="sticky top-0 z-10">
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('WO Number') }}</th>
                        <th class="py-2 px-3">{{ __('Product') }}</th>
                        <th class="py-2 px-3">{{ __('Formula') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Target') }}</th>
                        <th class="py-2 px-3">{{ __('Planned Start') }}</th>
                        <th class="py-2 px-3">{{ __('Planned End') }}</th>
                        <th class="py-2 px-3">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->workOrders as $workOrder)
                        @php($isLate = $workOrder->planned_end_date->isPast() && !$workOrder->status->isTerminal())
                        <tr wire:key="wo-row-{{ $workOrder->id }}" wire:transition class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                            <td class="py-2 px-3 whitespace-nowrap">
                                <a href="{{ route('production.work-orders.show', $workOrder) }}" wire:navigate class="font-data text-accent hover:underline">
                                    {{ $workOrder->wo_number }}
                                </a>
                            </td>
                            <td class="py-2 px-3">
                                <p class="text-zinc-700 dark:text-zinc-300">{{ $workOrder->product->product_name }}</p>
                                <p class="font-data text-[11px] text-zinc-400 dark:text-zinc-500">{{ $workOrder->product->product_code }}</p>
                            </td>
                            <td class="py-2 px-3 font-data whitespace-nowrap text-zinc-600 dark:text-zinc-400">
                                {{ $workOrder->productionFormula->formula_code }} <span class="text-zinc-400 dark:text-zinc-500">v{{ $workOrder->productionFormula->version }}</span>
                            </td>
                            <td class="py-2 px-3 text-right font-data font-medium tabular-nums whitespace-nowrap text-zinc-900 dark:text-white">
                                {{ $this->formatQuantity((string) $workOrder->quantity_target) }} {{ $workOrder->product->unit_of_measure }}
                            </td>
                            <td class="py-2 px-3 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $workOrder->planned_start_date->format('d M Y') }}</td>
                            <td class="py-2 px-3 whitespace-nowrap">
                                @if ($isLate)
                                    <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 font-data tabular-nums text-red-700 dark:text-red-400">
                                        <flux:icon.clock variant="micro" class="size-3" />
                                        {{ $workOrder->planned_end_date->format('d M Y') }}
                                    </span>
                                @else
                                    <span class="font-data tabular-nums text-zinc-600 dark:text-zinc-400">{{ $workOrder->planned_end_date->format('d M Y') }}</span>
                                @endif
                            </td>
                            <td class="py-2 px-3">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap {{ $this->statusBadgeClasses($workOrder->status) }}">
                                    {{ $workOrder->status->label() }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center">
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No work orders match your filters.') }}</p>
                                @can('create', WorkOrder::class)
                                    <flux:button size="sm" variant="primary" class="mt-4 active:scale-[0.95]" icon="document-plus" :href="route('production.work-orders.create')" wire:navigate>
                                        {{ __('Create Work Order') }}
                                    </flux:button>
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile List --}}
        <div class="mt-4 lg:hidden rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
            @forelse ($this->workOrders as $workOrder)
                @php($isLate = $workOrder->planned_end_date->isPast() && !$workOrder->status->isTerminal())
                <a wire:key="wo-card-{{ $workOrder->id }}" wire:transition href="{{ route('production.work-orders.show', $workOrder) }}" wire:navigate
                   class="block px-4 py-3 hover:bg-zinc-50 dark:hover:bg-white/3 active:scale-[0.98] transition-[background-color,transform]">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-data font-medium text-accent">{{ $workOrder->wo_number }}</span>
                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap {{ $this->statusBadgeClasses($workOrder->status) }}">
                            {{ $workOrder->status->label() }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $workOrder->product->product_name }}</p>
                    <p class="mt-1 text-xs font-data text-zinc-500 dark:text-zinc-400">
                        {{ $workOrder->productionFormula->formula_code }} v{{ $workOrder->productionFormula->version }}
                    </p>
                    <div class="mt-2 flex items-center justify-between gap-2 text-xs">
                        @if ($isLate)
                            <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 font-data tabular-nums text-red-700 dark:text-red-400">
                                <flux:icon.clock variant="micro" class="size-3" />
                                {{ $workOrder->planned_start_date->format('d M') }} – {{ $workOrder->planned_end_date->format('d M Y') }}
                            </span>
                        @else
                            <span class="font-data tabular-nums text-zinc-500 dark:text-zinc-400">{{ $workOrder->planned_start_date->format('d M') }} – {{ $workOrder->planned_end_date->format('d M Y') }}</span>
                        @endif
                        <span class="font-data font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $workOrder->quantity_target) }} {{ $workOrder->product->unit_of_measure }}</span>
                    </div>
                </a>
            @empty
                <div class="py-12 text-center px-4">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No work orders match your filters.') }}</p>
                    @can('create', WorkOrder::class)
                        <flux:button size="sm" variant="primary" class="mt-4 active:scale-[0.95]" icon="document-plus" :href="route('production.work-orders.create')" wire:navigate>
                            {{ __('Create Work Order') }}
                        </flux:button>
                    @endcan
                </div>
            @endforelse
        </div>
    </div>

    <div class="mt-4 motion-safe:animate-fade-slide-up" style="animation-delay: 160ms;">
        {{ $this->workOrders->links() }}
    </div>
</section>
