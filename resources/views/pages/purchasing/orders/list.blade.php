<?php

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Purchase Orders')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $sort = 'order_date_desc';

    public function mount(): void
    {
        Gate::authorize('purchasing.view');
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
        return PurchaseOrderStatus::cases();
    }

    protected function searchFilteredQuery(): Builder
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return PurchaseOrder::query()
            ->when($term !== '', function (Builder $query) use ($term) {
                $query->where(function (Builder $query) use ($term) {
                    $query->where('po_number', 'like', "%{$term}%")
                        ->orWhereHas('supplier', function (Builder $query) use ($term) {
                            $query->where('supplier_name', 'like', "%{$term}%");
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
    public function orders(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->filteredQuery()
            ->with(['supplier', 'createdBy'])
            ->orderBy(
                $this->sort === 'total_amount_desc' ? 'total_amount' : 'order_date',
                $this->sort === 'order_date_asc' ? 'asc' : 'desc',
            )
            ->paginate(10);
    }

    #[Computed]
    public function filteredTotalValue(): string
    {
        return (string) $this->filteredQuery()->sum('total_amount');
    }

    #[Computed]
    public function overdueCount(): int
    {
        return $this->filteredQuery()
            ->whereNotNull('expected_delivery_date')
            ->whereDate('expected_delivery_date', '<', now()->toDateString())
            ->whereNotIn('status', [
                PurchaseOrderStatus::FullyReceived,
                PurchaseOrderStatus::Cancelled,
            ])
            ->count();
    }

    /**
     * Counts per status for the filter tabs — deliberately scoped to the
     * search term only (not the currently selected status), so every tab
     * always shows what it would contain if clicked, not just the active one.
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

    public function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    public function statusBadgeClasses(PurchaseOrderStatus $status): string
    {
        return match ($status) {
            PurchaseOrderStatus::Draft => 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:border-white/10',
            PurchaseOrderStatus::PendingApproval => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20',
            PurchaseOrderStatus::Approved => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-400 dark:border-blue-500/20',
            PurchaseOrderStatus::Rejected => 'bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20',
            PurchaseOrderStatus::PartiallyReceived => 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-500/10 dark:text-purple-400 dark:border-purple-500/20',
            PurchaseOrderStatus::FullyReceived => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
            PurchaseOrderStatus::Cancelled => 'bg-zinc-100 text-zinc-500 border-zinc-200 line-through dark:bg-white/5 dark:text-zinc-500 dark:border-white/10',
        };
    }
}; ?>

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('purchasing.dashboard')" wire:navigate>{{ __('Purchasing') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Purchase Orders') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="font-mono text-[11px] font-semibold tracking-[0.2em] uppercase text-accent mb-2">
                {{ __('Purchasing') }} &middot; {{ __('Order Registry') }}
            </p>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ __('Purchase Orders') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>

        @can('create', PurchaseOrder::class)
            <flux:button variant="primary" icon="document-plus" :href="route('purchasing.orders.create')" wire:navigate>
                {{ __('New Purchase Order') }}
            </flux:button>
        @endcan
    </div>

    {{-- Status tabs — the domain's central axis, so it gets first-class filter UI
         instead of living inside a generic dropdown. Counts scoped to the active
         search term only, so every tab reflects what it would show if selected. --}}
    <div class="mt-6 flex items-center gap-1.5 overflow-x-auto pb-1">
        <button type="button" wire:click="$set('status', '')" wire:loading.attr="disabled"
            class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150
                {{ $status === '' ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
            {{ __('All') }}
            <span class="font-data tabular-nums {{ $status === '' ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->totalMatchingSearch }}</span>
        </button>

        @foreach ($this->statusOptions as $option)
            <button type="button" wire:click="$set('status', '{{ $option->value }}')" wire:loading.attr="disabled"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150
                    {{ $status === $option->value ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
                {{ $option->label() }}
                <span class="font-data tabular-nums {{ $status === $option->value ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->statusCounts[$option->value] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    {{-- Toolbar --}}
    <div class="mt-4 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-48">
            <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search PO number or supplier...')" />
        </div>

        <div class="w-44">
            <flux:select size="sm" wire:model.live="sort">
                <option value="order_date_desc">{{ __('Newest first') }}</option>
                <option value="order_date_asc">{{ __('Oldest first') }}</option>
                <option value="total_amount_desc">{{ __('Highest value first') }}</option>
            </flux:select>
        </div>

        @if ($search !== '' || $status !== '')
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">
                {{ __('Clear filters') }}
            </flux:button>
        @endif

        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="$refresh" wire:loading.attr="disabled" class="ml-auto">
            {{ __('Refresh') }}
        </flux:button>
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,status,sort" class="transition-opacity duration-150">
        <div wire:loading wire:target="search,status,sort" class="mt-3 flex items-center gap-1.5 text-xs text-accent">
            <flux:icon.loading class="size-3.5" />
            {{ __('Updating...') }}
        </div>

        {{-- Desktop/tablet table --}}
        <div class="mt-4 hidden lg:block overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10">
            <table class="w-full text-xs border-collapse">
                <thead class="sticky top-0 z-10">
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('PO Number') }}</th>
                        <th class="py-2 px-3">{{ __('Supplier') }}</th>
                        <th class="py-2 px-3">{{ __('Created By') }}</th>
                        <th class="py-2 px-3">{{ __('Order Date') }}</th>
                        <th class="py-2 px-3">{{ __('Expected Delivery') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Total') }}</th>
                        <th class="py-2 px-3">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->orders as $order)
                        @php($isLate = $order->expected_delivery_date?->isPast() && !$order->status->isTerminal())
                        <tr wire:key="po-row-{{ $order->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                            <td class="py-2 px-3 whitespace-nowrap">
                                <a href="{{ route('purchasing.orders.show', $order) }}" wire:navigate class="font-data text-accent hover:underline">
                                    {{ $order->po_number }}
                                </a>
                            </td>
                            <td class="py-2 px-3 text-zinc-700 dark:text-zinc-300">{{ $order->supplier->supplier_name }}</td>
                            <td class="py-2 px-3 text-zinc-500 dark:text-zinc-400">{{ $order->createdBy->name }}</td>
                            <td class="py-2 px-3 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $order->order_date?->format('d M Y') }}</td>
                            <td class="py-2 px-3 whitespace-nowrap">
                                @if ($isLate)
                                    <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 font-data tabular-nums text-red-700 dark:text-red-400">
                                        <flux:icon.clock variant="micro" class="size-3" />
                                        {{ $order->expected_delivery_date->format('d M Y') }}
                                    </span>
                                @else
                                    <span class="font-data tabular-nums text-zinc-600 dark:text-zinc-400">{{ $order->expected_delivery_date?->format('d M Y') ?? '—' }}</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 text-right font-data font-medium tabular-nums whitespace-nowrap text-zinc-900 dark:text-white">
                                {{ $this->formatRupiah($order->total_amount) }}
                            </td>
                            <td class="py-2 px-3">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap {{ $this->statusBadgeClasses($order->status) }}">
                                    {{ $order->status->label() }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center">
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No purchase orders match your filters.') }}</p>
                                @can('create', PurchaseOrder::class)
                                    <flux:button size="sm" variant="primary" class="mt-4" icon="document-plus" :href="route('purchasing.orders.create')" wire:navigate>
                                        {{ __('Create Purchase Order') }}
                                    </flux:button>
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile/narrow-tablet card list — table columns don't fit below `lg`
             without horizontal scroll, so this is a genuinely different layout,
             not a squeezed table. --}}
        <div class="mt-4 lg:hidden rounded-md border border-zinc-200 dark:border-white/10 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
            @forelse ($this->orders as $order)
                @php($isLate = $order->expected_delivery_date?->isPast() && !$order->status->isTerminal())
                <a wire:key="po-card-{{ $order->id }}" href="{{ route('purchasing.orders.show', $order) }}" wire:navigate
                   class="block px-4 py-3 hover:bg-zinc-50 dark:hover:bg-white/[0.03] transition-colors">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-data font-medium text-accent">{{ $order->po_number }}</span>
                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap {{ $this->statusBadgeClasses($order->status) }}">
                            {{ $order->status->label() }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $order->supplier->supplier_name }}</p>
                    <div class="mt-2 flex items-center justify-between gap-2 text-xs">
                        @if ($isLate)
                            <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 font-data tabular-nums text-red-700 dark:text-red-400">
                                <flux:icon.clock variant="micro" class="size-3" />
                                {{ $order->expected_delivery_date->format('d M Y') }}
                            </span>
                        @else
                            <span class="font-data tabular-nums text-zinc-500 dark:text-zinc-400">{{ $order->expected_delivery_date?->format('d M Y') ?? __('No delivery date') }}</span>
                        @endif
                        <span class="font-data font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($order->total_amount) }}</span>
                    </div>
                </a>
            @empty
                <div class="py-12 text-center px-4">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No purchase orders match your filters.') }}</p>
                    @can('create', PurchaseOrder::class)
                        <flux:button size="sm" variant="primary" class="mt-4" icon="document-plus" :href="route('purchasing.orders.create')" wire:navigate>
                            {{ __('Create Purchase Order') }}
                        </flux:button>
                    @endcan
                </div>
            @endforelse
        </div>
    </div>

    {{-- Filtered-set summary --}}
    <div class="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 rounded-md border border-zinc-200 dark:border-white/10 border-s-[3px] border-s-accent bg-zinc-50 dark:bg-white/[0.03] px-4 py-3 text-xs">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-zinc-500 dark:text-zinc-400">
            <span>
                <span class="font-data tabular-nums font-medium text-zinc-900 dark:text-white">{{ $this->orders->total() }}</span>
                {{ __('orders match the current filters') }}
            </span>
            <span class="hidden sm:inline text-zinc-300 dark:text-zinc-600">&middot;</span>
            <span>
                {{ __('Total value') }}
                <span class="font-data tabular-nums font-medium text-sm text-zinc-900 dark:text-white">{{ $this->formatRupiah($this->filteredTotalValue) }}</span>
            </span>
        </div>

        @if ($this->overdueCount > 0)
            <div class="inline-flex items-center gap-1.5 font-medium text-red-600 dark:text-red-400">
                <flux:icon.exclamation-triangle variant="micro" class="size-3.5" />
                {{ $this->overdueCount }} {{ __('of these are overdue on delivery') }}
            </div>
        @endif
    </div>

    <div class="mt-4">
        {{ $this->orders->links() }}
    </div>
</section>
