<?php

use App\Actions\Warehouse\CancelSalesDeliveryAction;
use App\Actions\Warehouse\PostSalesDeliveryAction;
use App\Enums\DocumentStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use App\Exceptions\InvalidSalesDeliveryException;
use App\Exceptions\InvalidSalesOrderStatusTransitionException;
use App\Exceptions\SalesDeliveryNotEditableException;
use App\Models\SalesDelivery;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales Delivery')] class extends Component {
    public SalesDelivery $salesDelivery;

    public function mount(): void
    {
        Gate::authorize('view', $this->salesDelivery);
        $this->loadRelations();
    }

    protected function loadRelations(): void
    {
        $this->salesDelivery->load([
            'salesOrder.customer', 'items.product', 'items.salesOrderItem', 'items.stockMovements.inventoryLot',
            'createdBy', 'deliveredBy',
        ]);
    }

    public function journalPlan(): ?array
    {
        if ($this->salesDelivery->status->value !== 'draft') {
            return null;
        }

        try {
            return app(\App\Actions\Warehouse\PostSalesDeliveryAction::class)->preview($this->salesDelivery);
        } catch (\Throwable) {
            return null;
        }
    }

    public function post(): void
    {
        Gate::authorize('post', $this->salesDelivery);

        try {
            app(PostSalesDeliveryAction::class)->handle($this->salesDelivery, Auth::id());
        } catch (InsufficientStockException|InvalidSalesDeliveryException|SalesDeliveryNotEditableException|InvalidDocumentStatusTransitionException|InvalidSalesOrderStatusTransitionException $e) {
            $this->modal('post-delivery')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->salesDelivery->refresh();
        $this->loadRelations();
        $this->modal('post-delivery')->close();
        Flux::toast(variant: 'success', text: __('Posted — the goods have left the warehouse.'));
    }

    public function cancel(): void
    {
        Gate::authorize('cancel', $this->salesDelivery);

        try {
            app(CancelSalesDeliveryAction::class)->handle($this->salesDelivery);
        } catch (SalesDeliveryNotEditableException|InvalidDocumentStatusTransitionException $e) {
            $this->modal('cancel-delivery')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->salesDelivery->refresh();
        $this->modal('cancel-delivery')->close();
        Flux::toast(variant: 'success', text: __('Delivery cancelled.'));
    }

    public function formatQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    public function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    public function documentBadgeClasses(DocumentStatus $status): string
    {
        return match ($status) {
            DocumentStatus::Draft => 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:border-white/10',
            DocumentStatus::Posted => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
            DocumentStatus::Cancelled => 'bg-zinc-100 text-zinc-500 border-zinc-200 line-through dark:bg-white/5 dark:text-zinc-500 dark:border-white/10',
        };
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

@php($delivery = $salesDelivery)
@php($order = $delivery->salesOrder)
@php($posted = $delivery->status === DocumentStatus::Posted)
@php($showCost = auth()->user()->can('costs.view'))
@php($label = 'text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('warehouse.outbound.sales-deliveries.list', ['tab' => 'documents'])" wire:navigate>{{ __('Sales Delivery') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $delivery->delivery_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-data text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $delivery->delivery_number }}</h1>
                <span wire:key="status-{{ $delivery->status->value }}" wire:transition class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $this->documentBadgeClasses($delivery->status) }}">{{ $delivery->status->label() }}</span>
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                {{ __('For') }} <span class="font-data text-accent">{{ $order->so_number }}</span> · {{ $order->customer->customer_name }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" :href="route('warehouse.outbound.sales-deliveries.list', ['tab' => 'documents'])" wire:navigate class="active:scale-[0.95]">{{ __('Back to List') }}</flux:button>
            @can('update', $delivery)
                <flux:button variant="filled" icon="pencil-square" :href="route('warehouse.outbound.sales-deliveries.edit', $delivery)" wire:navigate class="active:scale-[0.95]">{{ __('Edit') }}</flux:button>
            @endcan
            @can('cancel', $delivery)
                <flux:modal.trigger name="cancel-delivery">
                    <flux:button variant="ghost" icon="x-mark" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button>
                </flux:modal.trigger>
            @endcan
            @can('post', $delivery)
                <flux:modal.trigger name="post-delivery">
                    <flux:button variant="primary" icon="truck" class="active:scale-[0.95]">{{ __('Post') }}</flux:button>
                </flux:modal.trigger>
            @endcan
        </div>
    </div>

    <div class="mt-6 grid grid-cols-2 {{ $showCost ? 'sm:grid-cols-4' : 'sm:grid-cols-3' }} rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y sm:divide-y-0 sm:divide-x divide-zinc-100 dark:divide-white/5 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="px-4 py-3">
            <p class="{{ $label }}">{{ __('Customer') }}</p>
            <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-200">{{ $order->customer->customer_name }}</p>
            <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $order->delivery_address ?? '—' }}</p>
            @if ($order->contact_person || $order->contact_phone)
                <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ collect([$order->contact_person, $order->contact_phone])->filter()->join(' · ') }}</p>
            @endif
        </div>
        <div class="px-4 py-3">
            <p class="{{ $label }}">{{ __('Created by') }}</p>
            <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-200">{{ $delivery->createdBy->name }}</p>
            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $delivery->created_at->format('d M Y H:i') }}</p>
        </div>
        <div class="px-4 py-3">
            <p class="{{ $label }}">{{ __('Delivered by') }}</p>
            <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-200">{{ $delivery->deliveredBy?->name ?? '—' }}</p>
            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $delivery->delivered_at?->format('d M Y H:i') }}</p>
        </div>
        @if ($showCost)
            <div class="px-4 py-3">
                <p class="{{ $label }}">{{ __('Cost of goods sold') }}</p>
                <p class="mt-1 font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $posted ? $this->formatRupiah($delivery->totalCogs()) : '—' }}</p>
                @unless ($posted) <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Set by FIFO on posting') }}</p> @endunless
            </div>
        @endif
    </div>

    @if ($delivery->notes)
        <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Notes') }}: “{{ $delivery->notes }}”</p>
    @endif

    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">{{ __('Products') }}</h2>
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5">
            @foreach ($delivery->items as $item)
                @php($unit = $item->product->unit_of_measure)
                <div wire:key="line-{{ $item->id }}" class="px-4 py-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm text-zinc-900 dark:text-white">{{ $item->product->product_name }}</p>
                            <p class="font-data text-[11px] text-accent">{{ $item->product->product_code }}</p>
                        </div>
                        <div class="text-right">
                            <p class="font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $item->quantity) }} {{ $unit }}</p>
                            <p class="font-data text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400">{{ __('of :qty ordered', ['qty' => $this->formatQuantity((string) $item->salesOrderItem->quantity_ordered)]) }}</p>
                            @if ($posted && $showCost)
                                <p class="font-data text-xs tabular-nums text-zinc-700 dark:text-zinc-300">{{ __('COGS') }} {{ $this->formatRupiah((string) $item->cogs_total) }}</p>
                            @endif
                        </div>
                    </div>

                    @if ($posted && $item->stockMovements->isNotEmpty())
                        <div class="mt-2 rounded-md bg-zinc-50 dark:bg-white/[0.03] px-3 py-2">
                            <p class="{{ $label }} mb-1">{{ __('Drawn from (oldest lot first)') }}</p>
                            @foreach ($item->stockMovements->sortBy('id') as $movement)
                                <div class="flex items-center justify-between gap-3 font-data text-[11px] tabular-nums text-zinc-600 dark:text-zinc-400">
                                    <span>{{ $movement->inventoryLot->lot_number }} <span class="text-zinc-400 dark:text-zinc-500">· {{ $movement->inventoryLot->received_at->format('d M Y') }}</span></span>
                                    @if ($showCost)
                                        <span>{{ $this->formatQuantity((string) $movement->quantity) }} × {{ $this->formatRupiah((string) $movement->unit_cost) }} = {{ $this->formatRupiah((string) $movement->total_value) }}</span>
                                    @else
                                        <span>{{ $this->formatQuantity((string) $movement->quantity) }} {{ $unit }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    @can('post', $delivery)
        <flux:modal name="post-delivery" focusable class="max-w-xl">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Post this delivery?') }}</flux:heading>
                    <flux:subheading>{{ __('The goods leave the warehouse now, taken from the oldest lots first. A posted delivery can no longer be changed.') }}</flux:subheading>
                </div>
                <x-journal-confirm :plan="$this->journalPlan()" :hide-amounts="! \Illuminate\Support\Facades\Gate::allows('costs.view')" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="post" wire:loading.attr="disabled" wire:target="post" class="active:scale-[0.95]">{{ __('Post') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan

    @can('cancel', $delivery)
        <flux:modal name="cancel-delivery" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Cancel this draft?') }}</flux:heading>
                    <flux:subheading>{{ __('Nothing has left the warehouse yet, so no stock changes.') }}</flux:subheading>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Keep draft') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel" class="active:scale-[0.95]">{{ __('Cancel draft') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan
</section>
