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

    #[Computed]
    public function statusOptions(): array
    {
        return PurchaseOrderStatus::cases();
    }

    protected function filteredQuery(): Builder
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
            })
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
        </div>

        @can('create', PurchaseOrder::class)
            <flux:button variant="primary" icon="document-plus" :href="route('purchasing.orders.create')" wire:navigate>
                {{ __('New Purchase Order') }}
            </flux:button>
        @endcan
    </div>

    {{-- Toolbar --}}
    <div class="mt-6 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-48">
            <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search PO number or supplier...')" />
        </div>

        <div class="w-44">
            <flux:select size="sm" wire:model.live="status" :placeholder="__('All statuses')">
                @foreach ($this->statusOptions as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </flux:select>
        </div>

        <div class="w-44">
            <flux:select size="sm" wire:model.live="sort">
                <option value="order_date_desc">{{ __('Newest first') }}</option>
                <option value="order_date_asc">{{ __('Oldest first') }}</option>
                <option value="total_amount_desc">{{ __('Highest value first') }}</option>
            </flux:select>
        </div>

        <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="$refresh" wire:loading.attr="disabled">
            {{ __('Refresh') }}
        </flux:button>
    </div>

    {{-- Table --}}
    <div class="mt-4 overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10">
        <table class="w-full text-sm border-collapse">
            <thead class="sticky top-0 z-10">
                <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                    <th class="py-2.5 px-3">{{ __('PO Number') }}</th>
                    <th class="py-2.5 px-3">{{ __('Supplier') }}</th>
                    <th class="py-2.5 px-3">{{ __('Created By') }}</th>
                    <th class="py-2.5 px-3">{{ __('Order Date') }}</th>
                    <th class="py-2.5 px-3">{{ __('Expected Delivery') }}</th>
                    <th class="py-2.5 px-3 text-right">{{ __('Total') }}</th>
                    <th class="py-2.5 px-3">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->orders as $order)
                    <tr wire:key="po-row-{{ $order->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/3 transition-colors">
                        <td class="py-2.5 px-3 whitespace-nowrap">
                            <a href="{{ route('purchasing.orders.show', $order) }}" wire:navigate class="font-mono text-accent hover:underline">
                                {{ $order->po_number }}
                            </a>
                        </td>
                        <td class="py-2.5 px-3 text-zinc-700 dark:text-zinc-300">{{ $order->supplier->supplier_name }}</td>
                        <td class="py-2.5 px-3 text-zinc-500 dark:text-zinc-400">{{ $order->createdBy->name }}</td>
                        <td class="py-2.5 px-3 font-mono tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $order->order_date?->format('d M Y') }}</td>
                        <td class="py-2.5 px-3 font-mono tabular-nums whitespace-nowrap {{ $order->expected_delivery_date?->isPast() && !$order->status->isTerminal() ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-zinc-600 dark:text-zinc-400' }}">
                            {{ $order->expected_delivery_date?->format('d M Y') ?? '—' }}
                        </td>
                        <td class="py-2.5 px-3 text-right font-mono font-semibold tabular-nums whitespace-nowrap text-zinc-800 dark:text-zinc-200">
                            {{ $this->formatRupiah($order->total_amount) }}
                        </td>
                        <td class="py-2.5 px-3">
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

    {{-- Filtered-set summary --}}
    <div class="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 rounded-md border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/[0.03] px-4 py-3 text-xs">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-zinc-500 dark:text-zinc-400">
            <span>
                <span class="font-mono tabular-nums font-semibold text-zinc-800 dark:text-zinc-200">{{ $this->orders->total() }}</span>
                {{ __('orders match the current filters') }}
            </span>
            <span class="hidden sm:inline text-zinc-300 dark:text-zinc-600">&middot;</span>
            <span>
                {{ __('Total value') }}
                <span class="font-mono tabular-nums font-semibold text-zinc-800 dark:text-zinc-200">{{ $this->formatRupiah($this->filteredTotalValue) }}</span>
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
