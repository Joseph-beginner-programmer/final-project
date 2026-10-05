<?php
use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Illuminate\Database\Eloquent\Builder;
use App\Models\PurchaseOrder;
use App\Enums\PurchaseOrderStatus;

new #[Title('Item Receipt')] class extends Component
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
            PurchaseOrderStatus::Closed => 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-500/10 dark:text-slate-400 dark:border-slate-500/20',
        };
    }
};

?>

<style>
    @keyframes fade-slide-up {
        from { opacity: 0; transform: translateY(8px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .motion-safe\:animate-fade-slide-up {
        animation: fade-slide-up .4s cubic-bezier(.16,1,.3,1) both;
    }
    @media (prefers-reduced-motion: reduce) {
        .motion-safe\:animate-fade-slide-up { animation: none; }
    }
</style>

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('warehouse.dashboard')" wire:navigate>{{ __('Warehouse') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Item Receipt') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    {{-- Header --}}
    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ __('Item Receipt List') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('warehouse.inbound.item-receipts.create')" wire:navigate class="active:scale-[0.95]">
            {{ __('Create Item Receipt') }}
        </flux:button>
    </div>

    {{-- Status Tabs — Flux's segmented radio group ships a built-in animated sliding indicator,
         same control already used for the Appearance toggle in Settings. --}}
    <div class="mt-4 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;" wire:loading.class="opacity-60" wire:target="status">
        <flux:radio.group wire:model.live="status" variant="segmented" size="sm" class="w-fit">
            <flux:radio value="">{{ __('All') }}</flux:radio>
            <flux:radio value="{{ PurchaseOrderStatus::Approved->value }}">{{ PurchaseOrderStatus::Approved->label() }}</flux:radio>
            <flux:radio value="{{ PurchaseOrderStatus::PartiallyReceived->value }}">{{ PurchaseOrderStatus::PartiallyReceived->label() }}</flux:radio>
        </flux:radio.group>
    </div>

    {{-- Search Bar --}}
    <div class="my-3 motion-safe:animate-fade-slide-up" style="animation-delay: 80ms;">
        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search PO number or supplier...')"/>
    </div>

    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    {{-- Desktop View — the whole table as one elevated card.
         Deliberate departure from MASTER.md's flat/border-first rule, per explicit request. --}}
    <div
        class="mt-4 hidden lg:block overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 transition-opacity duration-300"
        wire:loading.class="opacity-40"
        wire:target="status,search,gotoPage,previousPage,nextPage"
    >
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 border-b border-zinc-100 dark:border-white/5">
                    <th class="py-3 px-4">{{ __('PO Number') }}</th>
                    <th class="py-3 px-4">{{ __('Supplier') }}</th>
                    <th class="py-3 px-4">{{ __('Order Date') }}</th>
                    <th class="py-3 px-4">{{ __('Expected Delivery') }}</th>
                    <th class="py-3 px-4 text-right">{{ __('Total') }}</th>
                    <th class="py-3 px-4">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->purchaseOrders as $order)
                    <tr
                        wire:key="po-row-{{ $order->id }}"
                        class="group border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-accent/5 dark:hover:bg-accent/10 transition-colors duration-200 motion-safe:animate-fade-slide-up"
                        style="animation-delay: {{ min($loop->index, 8) * 35 }}ms;"
                    >
                        <td class="py-4 px-4 whitespace-nowrap">
                            <span class="font-data font-medium text-accent">
                                <a href="{{ route('warehouse.inbound.item-receipts.detail', $order) }}" wire:navigate class="transition-colors duration-150 hover:underline underline-offset-2">{{ $order->po_number }}</a>
                            </span>
                        </td>
                        <td class="py-4 px-4 text-zinc-700 dark:text-zinc-300">{{ $order->supplier->supplier_name }}</td>
                        <td class="py-4 px-4 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $order->order_date?->format('d M Y') }}</td>
                        <td class="py-4 px-4 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $order->expected_delivery_date?->format('d M Y') ?? '—' }}</td>
                        <td class="py-4 px-4 text-right font-data font-medium tabular-nums whitespace-nowrap text-zinc-900 dark:text-white">
                            Rp {{ number_format((float) $order->total_amount, 0, ',', '.') }}
                        </td>
                        <td class="py-4 px-4">
                            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium whitespace-nowrap transition-transform duration-150 group-hover:scale-105 {{ $this->statusBadgeClasses($order->status) }}">
                                {{ $order->status->label() }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-12 px-4 text-center">
                            <flux:icon.inbox class="mx-auto size-8 text-zinc-300 dark:text-zinc-600 mb-2" />
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No purchase orders found.') }}</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Mobile View — the whole list as one elevated card, matching desktop --}}
    <div
        class="mt-4 lg:hidden rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden transition-opacity duration-300"
        wire:loading.class="opacity-40"
        wire:target="status,search,gotoPage,previousPage,nextPage"
    >
        @forelse($this->purchaseOrders as $order)
            <a
                wire:key="po-card-{{ $order->id }}"
                class="block px-4 py-3.5 hover:bg-accent/5 dark:hover:bg-accent/10 active:scale-[0.98] transition-all duration-150 cursor-pointer motion-safe:animate-fade-slide-up"
                style="animation-delay: {{ min($loop->index, 8) * 35 }}ms;"
                href="{{ route('warehouse.inbound.item-receipts.detail', $order) }}"
                wire:navigate
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="font-data font-medium text-accent text-base">{{ $order->po_number }}</span>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-medium whitespace-nowrap {{ $this->statusBadgeClasses($order->status) }}">
                        {{ $order->status->label() }}
                    </span>
                </div>
                <p class="mt-1 text-base text-zinc-700 dark:text-zinc-300">{{ $order->supplier->supplier_name }}</p>
                <div class="mt-2 flex items-center justify-between gap-2 text-sm">
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
                <flux:icon.inbox class="mx-auto size-8 text-zinc-300 dark:text-zinc-600 mb-2" />
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No purchase orders found.') }}</p>
            </div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $this->purchaseOrders->links() }}
    </div>
</section>
