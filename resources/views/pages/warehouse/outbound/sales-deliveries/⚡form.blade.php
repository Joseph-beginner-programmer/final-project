<?php

use App\Actions\Warehouse\SaveSalesDeliveryDraftAction;
use App\Exceptions\InvalidSalesDeliveryException;
use App\Exceptions\SalesDeliveryNotEditableException;
use App\Models\SalesDelivery;
use App\Models\SalesOrder;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales Delivery')] class extends Component {
    #[Locked]
    public int $salesOrderId;

    #[Locked]
    public ?int $salesDeliveryId = null;

    public array $send = [];

    public string $notes = '';

    public function mount(?SalesOrder $salesOrder = null, ?SalesDelivery $salesDelivery = null): void
    {
        if ($salesDelivery?->exists) {
            Gate::authorize('update', $salesDelivery);
            $salesOrder = $salesDelivery->salesOrder;
            $this->salesDeliveryId = $salesDelivery->id;
            $this->notes = (string) $salesDelivery->notes;
        } else {
            Gate::authorize('create', SalesDelivery::class);

            $draft = $salesOrder->deliveries()->where('status', 'draft')->first();
            if ($draft) {
                $this->redirectRoute('warehouse.outbound.sales-deliveries.show', $draft, navigate: true);

                return;
            }
        }

        $this->salesOrderId = $salesOrder->id;

        $saved = $salesDelivery?->exists ? $salesDelivery->items->pluck('quantity', 'sales_order_item_id') : null;
        foreach ($salesOrder->items as $item) {
            $quantity = $saved ? (string) ($saved[$item->id] ?? '0') : $item->remainingQuantity();
            $this->send[$item->id] = ['quantity' => $this->formatQuantity($quantity)];
        }
    }

    #[Computed]
    public function order(): SalesOrder
    {
        return SalesOrder::with(['customer', 'items.product'])->findOrFail($this->salesOrderId);
    }

    #[Computed]
    public function rows(): Collection
    {
        return $this->order->items->map(function ($item) {
            $sending = $this->send[$item->id]['quantity'] ?? '';

            return [
                'item' => $item,
                'remaining' => $item->remainingQuantity(),
                'stock' => (string) $item->product->current_stock,
                'is_short' => is_numeric($sending) && bccomp((string) $sending, (string) $item->product->current_stock, 2) > 0,
            ];
        });
    }

    public function formatQuantity(string $quantity): string
    {
        if ($quantity === '' || ! str_contains($quantity, '.')) {
            return $quantity;
        }

        return rtrim(rtrim($quantity, '0'), '.');
    }

    protected function rules(): array
    {
        return [
            'send.*.quantity' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'send.*.quantity' => __('Send now'),
            'notes' => __('Notes'),
        ];
    }

    public function save(): void
    {
        $delivery = $this->salesDeliveryId ? SalesDelivery::findOrFail($this->salesDeliveryId) : null;
        $delivery ? Gate::authorize('update', $delivery) : Gate::authorize('create', SalesDelivery::class);

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Flux::toast(variant: 'danger', text: __('Please fix the highlighted fields before submitting.'));
            throw $e;
        }

        $quantities = collect($this->send)->mapWithKeys(fn ($row, $itemId) => [(int) $itemId => (string) ($row['quantity'] ?? '')])->all();

        try {
            $delivery = app(SaveSalesDeliveryDraftAction::class)->handle($this->order, $quantities, $this->notes, Auth::id(), $delivery);
        } catch (InvalidSalesDeliveryException|SalesDeliveryNotEditableException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Draft :number saved. Stock leaves only when it is posted.', ['number' => $delivery->delivery_number]));
        $this->redirectRoute('warehouse.outbound.sales-deliveries.show', $delivery, navigate: true);
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

@php($order = $this->order)
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('warehouse.dashboard')" wire:navigate>{{ __('Warehouse') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('warehouse.outbound.sales-deliveries.list')" wire:navigate>{{ __('Sales Delivery') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $salesDeliveryId ? __('Edit') : __('Create') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ $salesDeliveryId ? __('Edit Delivery') : __('New Delivery') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                {{ __('For') }} <span class="font-data text-accent">{{ $order->so_number }}</span> · {{ $order->customer->customer_name }}
                @if ($order->expected_delivery_date) · {{ __('Expected Delivery') }} <span class="font-data tabular-nums">{{ $order->expected_delivery_date->format('d M Y') }}</span> @endif
            </p>
            @if ($order->delivery_address)
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">{{ __('Delivery Address') }}: <span class="text-zinc-700 dark:text-zinc-300">{{ $order->delivery_address }}</span></p>
            @endif
            @if ($order->contact_person || $order->contact_phone)
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">{{ __('Contact') }}: <span class="text-zinc-700 dark:text-zinc-300">{{ collect([$order->contact_person, $order->contact_phone])->filter()->join(' · ') }}</span></p>
            @endif
        </div>

        <flux:button variant="primary" icon="document-check" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">
            {{ __('Save Draft') }}
        </flux:button>
    </div>

    <div class="mt-6 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">{{ __('Products') }}</h2>

        @php($grid = 'grid grid-cols-2 md:grid-cols-[minmax(0,1fr)_7rem_9rem_7rem_7.5rem_10rem] gap-x-3 gap-y-2 items-start')
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <div class="{{ $grid }} hidden md:grid px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10 rounded-t-xl">
                <span>{{ __('Product') }}</span>
                <span class="text-right">{{ __('Ordered') }}</span>
                <span class="text-right whitespace-nowrap">{{ __('Already delivered') }}</span>
                <span class="text-right">{{ __('Remaining') }}</span>
                <span class="text-right">{{ __('In Stock') }}</span>
                <span class="text-right">{{ __('Send now') }}</span>
            </div>

            @foreach ($this->rows as $row)
                @php($item = $row['item'])
                @php($unit = $item->product->unit_of_measure)
                <div wire:key="send-{{ $item->id }}" class="{{ $grid }} px-4 py-3 border-b border-zinc-100 dark:border-white/5 last:border-0">
                    <div class="col-span-2 md:col-span-1 min-w-0">
                        <p class="text-sm text-zinc-900 dark:text-white">{{ $item->product->product_name }}</p>
                        <p class="font-data text-[11px] text-accent">{{ $item->product->product_code }}</p>
                    </div>

                    @foreach ([__('Ordered') => (string) $item->quantity_ordered, __('Already delivered') => (string) $item->quantity_delivered, __('Remaining') => $row['remaining']] as $label => $value)
                        <div class="md:text-right text-xs">
                            <p class="md:hidden text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                            <p class="md:pt-2 font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity($value) }} {{ $unit }}</p>
                        </div>
                    @endforeach

                    <div class="md:text-right text-xs">
                        <p class="md:hidden text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('In Stock') }}</p>
                        <p class="md:pt-2 font-data tabular-nums {{ $row['is_short'] ? 'text-red-600 dark:text-red-400 font-medium' : 'text-zinc-700 dark:text-zinc-300' }}">{{ $this->formatQuantity($row['stock']) }} {{ $unit }}</p>
                    </div>

                    <div class="col-span-2 md:col-span-1">
                        <p class="md:hidden mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Send now') }}</p>
                        @if (bccomp($row['remaining'], '0', 2) > 0)
                            <flux:input.group>
                                <flux:input size="sm" type="text" inputmode="numeric" pattern="[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false"
                                    wire:model.live.debounce.400ms="send.{{ $item->id }}.quantity" :aria-label="__('Send now').' — '.$item->product->product_name" />
                                <flux:input.group.suffix class="px-2 text-xs">{{ $unit }}</flux:input.group.suffix>
                            </flux:input.group>
                            @error("send.{$item->id}.quantity") <flux:error class="mt-1" :message="$message" /> @enderror
                        @else
                            <p class="md:pt-2 md:text-right text-xs text-green-600 dark:text-green-400">{{ __('Fully delivered') }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Leave a product at 0 to send it later. More than the remaining quantity cannot be sent.') }}</p>

        <div class="mt-4 max-w-xl">
            <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" :placeholder="__('e.g. driver, vehicle, or delivery note number')" />
        </div>
    </div>
</section>
