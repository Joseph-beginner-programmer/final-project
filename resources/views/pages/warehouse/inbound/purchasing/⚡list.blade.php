<?php
use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Illuminate\Database\Eloquent\Builder;
use App\Models\PurchaseOrder;
use App\Enums\PurchaseOrderStatus;

new #[Title('Purchase Order Receipt')] class extends Component 
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    protected function searchFilterQuery(): Builder {
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

    protected function statusFilterQuery(): Builder {
        $status = $this->status;
        return $this->searchFilterQuery()
            ->when(
                $status !== '', 
                function (Builder $query) use ($status) {
                    $query->where('status', $status);
                },
                function (Builder $query) {
                    $query->whereIn('status', ['approved', 'partially_received']);
                },
            );
    }

    #[Computed]
    public function purchaseOrders(): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        return $this->statusFilterQuery()
            ->with('supplier')
            ->orderBy('order_date', 'desc')
            ->paginate(10);
    }
};

?>

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('warehouse.dashboard')" wire:navigate>{{ __('Warehouse') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Purchase Order Receipt') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    {{-- Header --}}
    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ __('Purchase Order Receipt List') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>
    </div>

    {{-- Status Bar --}}
    <div class="mt-4 flex items-center gap-1.5 overflow-x-auto pb-1">
        <button type="button" wire:click="$set('status', '')" wire:loading.attr="disabled"
            class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150
                {{ $status === '' ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
            {{ __('All') }}
        </button>

        <button type="button" wire:click="$set('status', '{{ PurchaseOrderStatus::Approved->value }}')" wire:loading.attr="disabled"
            class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150
                {{ $status === PurchaseOrderStatus::Approved->value ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
            {{ PurchaseOrderStatus::Approved->label() }}
        </button>

        <button type="button" wire:click="$set('status', '{{ PurchaseOrderStatus::PartiallyReceived->value }}')" wire:loading.attr="disabled"
            class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150
                {{ $status === PurchaseOrderStatus::PartiallyReceived->value ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
            {{ PurchaseOrderStatus::PartiallyReceived->label() }}
        </button>
    </div>

    {{-- Search Bar --}}
    <div class="my-2">
        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search PO number or supplier...')"/>
    </div>

    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    {{-- Desktop View --}}
    <div class="mt-4 hidden lg:block overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10">
        <table class="w-full text-xs border-collapse">
            <thead class="sticky top-0 z-10">
                <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                    <th class="py-2 px-3">{{ __('PO Number') }}</th>
                    <th class="py-2 px-3">{{ __('Supplier') }}</th>
                    <th class="py-2 px-3">{{ __('Order Date') }}</th>
                    <th class="py-2 px-3">{{ __('Expected Delivery') }}</th>
                    <th class="py-2 px-3 text-right">{{ __('Total') }}</th>
                    <th class="py-2 px-3">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->purchaseOrders as $order)
                    <tr wire:key="po-row-{{ $order->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/3 transition-colors">
                        <td class="py-2 px-3 whitespace-nowrap">
                            <span class="font-data font-medium text-accent">
                                <a href="{{ route('warehouse.inbound.purchase.detail', $order) }}">{{ $order->po_number }}</a> 
                            </span>
                        </td>
                        <td class="py-2 px-3 text-zinc-700 dark:text-zinc-300">{{ $order->supplier->supplier_name }}</td>
                        <td class="py-2 px-3 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $order->order_date?->format('d M Y') }}</td>
                        <td class="py-2 px-3 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $order->expected_delivery_date?->format('d M Y') ?? '—' }}</td>
                        <td class="py-2 px-3 text-right font-data font-medium tabular-nums whitespace-nowrap text-zinc-900 dark:text-white">
                            Rp {{ number_format((float) $order->total_amount, 0, ',', '.') }}
                        </td>
                        <td class="py-2 px-3">
                            <span class="inline-flex items-center rounded-full border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/5 px-2 py-0.5 text-[11px] font-medium text-zinc-600 dark:text-zinc-400 whitespace-nowrap">
                                {{ $order->status->label() }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-12 text-center">
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No purchase orders found.') }}</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Mobile View --}}
    <div class="mt-4 lg:hidden rounded-md border border-zinc-200 dark:border-white/10 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
        @forelse($this->purchaseOrders as $order)
            <a wire:key="po-card-{{ $order->id }}" class="block px-4 py-3 hover:bg-zinc-50 dark:hover:bg-white/3 transition-colors cursor-pointer" href="{{ route('warehouse.inbound.purchase.detail', $order) }}" wire:navigate>
                <div class="flex items-center justify-between gap-2">
                    <span class="font-data font-medium text-accent">{{ $order->po_number }}</span>
                    <span class="inline-flex items-center rounded-full border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/5 px-2 py-0.5 text-[11px] font-medium text-zinc-600 dark:text-zinc-400 whitespace-nowrap">
                        {{ $order->status->label() }}
                    </span>
                </div>
                <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ $order->supplier->supplier_name }}</p>
                <div class="mt-2 flex items-center justify-between gap-2 text-xs">
                    <span class="font-data tabular-nums text-zinc-500 dark:text-zinc-400">
                        {{ $order->expected_delivery_date?->format('d M Y') ?? __('No delivery date') }}
                    </span>
                    <span class="font-data font-medium tabular-nums text-zinc-900 dark:text-white">
                        Rp {{ number_format((float) $order->total_amount, 0, ',', '.') }}
                    </span>
                </div>
            </a>
        @empty
            <div class="py-12 text-center px-4">
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No purchase orders found.') }}</p>
            </div>
        @endforelse
    </div>
</section>