<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Support\Collection;

new #[Title('Purchase Order Receipt Detail')] class extends Component
{
    public PurchaseOrder $purchaseOrder;

    public function mount(PurchaseOrder $purchaseOrder): void {
        $this->purchaseOrder = $purchaseOrder->load(['supplier', 'createdBy', 'items.product']);
    }

    #[Computed]
    public function orderedQuantitySummary(): Collection {
        return $this->purchaseOrder->items
            ->groupBy(fn($item) => $item->product->unit_of_measure)
            ->map(fn (Collection $items) => $items->reduce(
                fn ($carry, $item) => bcadd($carry, $item->quantity_ordered, 2),
                '0'
            ));
    }

    #[Computed]
    public function receivedQuantitySummary(): Collection {
        return $this->purchaseOrder->items
            ->groupBy(fn ($item) => $item->product->unit_of_measure)
            ->map(fn (Collection $items) => $items->reduce(
                fn ($carry, $item) => bcadd($carry, $item->quantity_received, 2),
                '0'
            ));
    }

    #[Computed]
    public function receivingSummaryByUnit(): Collection {
        $allItems = new Collection;
        $allItems = $allItems->merge($this->orderedQuantitySummary);
        $allItems = $allItems->merge($this->receivedQuantitySummary);
        return $allItems;
    }

    public function formatQuantity(string $quantity): string {
        return rtrim(rtrim($quantity, '0'), '.');
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
};
?>

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('warehouse.dashboard')" wire:navigate>{{ __('Warehouse') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('warehouse.inbound.purchasing.list')">{{ __('Purchase Order Receipt') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $purchaseOrder->po_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    @php($isLate = $purchaseOrder->expected_delivery_date?->isPast() && !$purchaseOrder->status->isTerminal())
    @php($itemsReceivedCount = $purchaseOrder->items->filter->isFullyReceived()->count())

    {{-- PO Header --}}
    <div class="mt-6 rounded-md border px-3 py-4 border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 overflow-hidden">
        <div class="p-5 flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap gap-3 flex-col lg:flex-row">
                    <h1 class="font-data text-xl sm:text-2xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                        {{ $purchaseOrder->po_number }}
                    </h1>
                    <span class="inline-flex w-fit items-center rounded-full border px-2.5 py-0.5 text-[11px] font-medium {{ $this->statusBadgeClasses($purchaseOrder->status) }}">
                        {{ $purchaseOrder->status->label() }}
                    </span>
                </div>
                <div class="w-10 h-0.5 mt-2 rounded-full bg-accent hidden lg:block"></div>
            </div>

            <div class="text-right">
                <p class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</p>
                <p class="font-data text-xl font-medium tabular-nums text-zinc-900 dark:text-white mt-0.5">
                    {{ $this->formatRupiah((string) $purchaseOrder->total_amount) }}
                </p>
            </div>
        </div>

        <dl class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 px-5 py-4 border-t border-zinc-100 dark:border-white/10 bg-zinc-50/50 dark:bg-white/2 text-xs">
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Supplier') }}</dt>
                <dd class="font-medium text-zinc-900 dark:text-white">{{ $purchaseOrder->supplier->supplier_name }}</dd>
                <dd class="font-data text-accent text-[11px] mt-0.5">{{ $purchaseOrder->supplier->supplier_code }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Order Date') }}</dt>
                <dd class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $purchaseOrder->order_date?->format('d M Y') }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Expected Delivery') }}</dt>
                @if ($isLate)
                    <dd>
                        <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 font-data tabular-nums text-red-700 dark:text-red-400">
                            <flux:icon.clock variant="micro" class="size-3" />
                            {{ $purchaseOrder->expected_delivery_date->format('d M Y') }}
                        </span>
                    </dd>
                @else
                    <dd class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $purchaseOrder->expected_delivery_date?->format('d M Y') ?? '—' }}</dd>
                @endif
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Created By') }}</dt>
                <dd class="text-zinc-700 dark:text-zinc-300">{{ $purchaseOrder->createdBy->name }}</dd>
            </div>
        </dl>
    </div>

    {{-- Summary --}}
    <div class="mt-4 rounded-md border border- border-s-[3px] border-s-accent bg-whitedark:bg-zinc-900 overflow-hidden flex flex-col h-full max-w-sm">
        <div class="p-4 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">
                {{ __('Receiving Summary') }}
            </p>

            <div class="space-y-3">
                {{-- @foreach ($this->receivingSummaryByUnit as $unit => $summary)
                    <dl wire:key="summary-{{ $unit }}" class="space-y-1.5 text-xs">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-accent">{{ $unit }}</p>
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Ordered') }}</dt>
                            <dd class="font-da dark:text-zinc-300">{{$this->formatQuantity($summary['ordered']) }}</dd>
                        </div>
                        <div class="flex justi
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Received') }}</dt>
                            <dd class="font-data tabular-nums text-green-600 dark:text-green-400">{{ $this->formatQuantity($summary['received']) }}</dd>
                        </div>
                    </dl>
                @endforeach --}}
            </div>

            <div class="flex justify-between gr-zinc-100 dark:border-white/10 text-xs">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Rejected / Loss') }}</dt>
                <dd class="font-data tabular-nums text-red-600 dark:text-red-400">0</dd>
            </div>
        </div>

        {{-- <div class="px-4 py-3.5 bg-accent mt-auto">
            <p class="text-[10px] font-semibolext-accent-foreground/70">{{ __('Remainingto Receive') }}</p>
            @forelse ($this->receivingSummaryByUnit as $unit => $summary)
                <p class="font-data text-xl font-medium tabular-nums text-accent-foreground mt-0.5">
                    {{ $this->formatQuantity($summary['remaining']) }}
                    <span class="text-sm font-normal">{{ $unit }}</span>
                </p>
            @empty
                <p class="font-data text-xl font-medium tabular-nums text-accent-foreground mt-0.5">0</p>
            @endforelse
        </div> --}}
    </div>
</section>