<?php

use App\Actions\Sales\CancelSalesOrderAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Enums\DocumentStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\InvalidSalesOrderException;
use App\Exceptions\InvalidSalesOrderStatusTransitionException;
use App\Exceptions\SalesOrderNotEditableException;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Support\IncomingStock;
use App\Support\SalesAvailability;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales Order')] class extends Component {
    public SalesOrder $salesOrder;

    public string $cancelReason = '';

    public string $activeTab = 'products';

    public function mount(): void
    {
        Gate::authorize('view', $this->salesOrder);
        $this->loadRelations();
    }

    protected function loadRelations(): void
    {
        $this->salesOrder->load(['customer', 'salesperson', 'items.product', 'charges', 'deliveries.items', 'deliveries.salesInvoices', 'createdBy', 'confirmedBy', 'cancelledBy']);
        unset($this->lines, $this->incoming);
    }

    public function confirm(): void
    {
        Gate::authorize('confirm', $this->salesOrder);

        try {
            app(ConfirmSalesOrderAction::class)->handle($this->salesOrder, Auth::id());
        } catch (InvalidSalesOrderException|SalesOrderNotEditableException|InvalidSalesOrderStatusTransitionException $e) {
            $this->modal('confirm-so')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->salesOrder->refresh();
        $this->loadRelations();
        $this->modal('confirm-so')->close();
        Flux::toast(variant: 'success', text: __('Sales order confirmed — the warehouse can now deliver it.'));
    }

    public function cancelOrder(): void
    {
        Gate::authorize('cancel', $this->salesOrder);

        $this->validate(['cancelReason' => ['required', 'string', 'max:1000']], [], ['cancelReason' => __('Reason')]);

        try {
            app(CancelSalesOrderAction::class)->handle($this->salesOrder, Auth::id(), $this->cancelReason);
        } catch (InvalidSalesOrderException|InvalidSalesOrderStatusTransitionException $e) {
            $this->modal('cancel-so')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->cancelReason = '';
        $this->salesOrder->refresh();
        $this->loadRelations();
        $this->modal('cancel-so')->close();
        Flux::toast(variant: 'success', text: __('Sales order cancelled.'));
    }

    #[Computed]
    public function incoming(): array
    {
        return IncomingStock::forProducts($this->salesOrder->items->pluck('product_id'));
    }

    #[Computed]
    public function lines(): Collection
    {
        $order = $this->salesOrder;
        $open = in_array($order->status, [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyDelivered], true);

        $promised = SalesAvailability::reserved($order->items->pluck('product_id'), $order->id);

        return $order->items->map(function (SalesOrderItem $item) use ($open, $promised) {
            $stillToDeliver = $item->remainingQuantity();
            $stock = (string) $item->product->current_stock;
            $otherOrders = $promised[$item->product_id] ?? '0';
            $incoming = IncomingStock::total($this->incoming[$item->product_id] ?? []);
            $check = SalesAvailability::check($stillToDeliver, $stock, $otherOrders, $incoming);

            return [
                'item' => $item,
                'remaining' => $stillToDeliver,
                'stock' => $stock,
                'promised' => $otherOrders,
                'incoming' => $incoming,
                'shortage' => $check['shortage'],
                'status' => $open ? $check['status'] : null,
            ];
        });
    }

    public function formatQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    public function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    public function statusBadgeClasses(SalesOrderStatus $status): string
    {
        return match ($status) {
            SalesOrderStatus::Draft => 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:border-white/10',
            SalesOrderStatus::Confirmed => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-400 dark:border-blue-500/20',
            SalesOrderStatus::PartiallyDelivered => 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-500/10 dark:text-purple-400 dark:border-purple-500/20',
            SalesOrderStatus::FullyDelivered => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
            SalesOrderStatus::Cancelled => 'bg-zinc-100 text-zinc-500 border-zinc-200 line-through dark:bg-white/5 dark:text-zinc-500 dark:border-white/10',
        };
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

@php($order = $salesOrder)
@php($open = in_array($order->status, [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyDelivered], true))
@php($label = 'text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('sales.dashboard')" wire:navigate>{{ __('Sales') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('sales.orders.list')" wire:navigate>{{ __('Sales Orders') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $order->so_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-data text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $order->so_number }}</h1>
                <span wire:key="status-{{ $order->status->value }}" wire:transition class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium whitespace-nowrap {{ $this->statusBadgeClasses($order->status) }}">{{ $order->status->label() }}</span>
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">{{ __('Created by') }} {{ $order->createdBy->name }} · {{ $order->created_at->format('d M Y') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" :href="route('sales.orders.list')" wire:navigate class="active:scale-[0.95]">{{ __('Back to List') }}</flux:button>
            @can('update', $order)
                <flux:button variant="filled" icon="pencil-square" :href="route('sales.orders.edit', $order)" wire:navigate class="active:scale-[0.95]">{{ __('Edit') }}</flux:button>
            @endcan
            @can('cancel', $order)
                <flux:modal.trigger name="cancel-so">
                    <flux:button variant="ghost" icon="x-mark" class="text-red-600 hover:text-red-700 dark:text-red-400 active:scale-[0.95]">{{ __('Cancel Order') }}</flux:button>
                </flux:modal.trigger>
            @endcan
            @can('confirm', $order)
                <flux:modal.trigger name="confirm-so">
                    <flux:button variant="primary" icon="check-badge" class="active:scale-[0.95]">{{ __('Confirm') }}</flux:button>
                </flux:modal.trigger>
            @endcan
        </div>
    </div>

    @if ($order->status === SalesOrderStatus::Cancelled)
        <div class="mt-4 flex items-start gap-2.5 rounded-md border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/5 px-3.5 py-2.5">
            <flux:icon.x-circle variant="mini" class="size-4 mt-0.5 shrink-0 text-zinc-500 dark:text-zinc-400" />
            <div class="text-xs">
                <p class="font-semibold text-zinc-800 dark:text-zinc-200">{{ __('Cancelled by :name', ['name' => $order->cancelledBy?->name ?? '—']) }} · <span class="font-data tabular-nums">{{ $order->cancelled_at?->format('d M Y H:i') }}</span></p>
                @if ($order->cancel_reason) <p class="mt-0.5 text-zinc-600 dark:text-zinc-400">“{{ $order->cancel_reason }}”</p> @endif
            </div>
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="lg:col-span-2 rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-5">
            <p class="{{ $label }}">{{ __('Customer') }} · <span class="font-data">{{ $order->customer->customer_code }}</span></p>
            <p class="mt-1 font-display text-base font-bold text-zinc-900 dark:text-white">{{ $order->customer->customer_name }}</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ collect([$order->contact_person, $order->contact_phone])->filter()->join(' · ') ?: '—' }}</p>
            <p class="mt-2 text-xs text-zinc-700 dark:text-zinc-300"><span class="text-zinc-500 dark:text-zinc-400">{{ __('Delivery Address') }}:</span> {{ $order->delivery_address ?? '—' }}</p>
            <dl class="mt-4 pt-3 border-t border-zinc-100 dark:border-white/10 grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Order Date') }}</dt>
                    <dd class="mt-0.5 font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $order->order_date->format('d M Y') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Delivery Date') }}</dt>
                    <dd class="mt-0.5 font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $order->expected_delivery_date?->format('d M Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Payment Terms') }}</dt>
                    <dd class="mt-0.5 text-zinc-800 dark:text-zinc-200">{{ $order->payment_terms->label() }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Salesperson') }}</dt>
                    <dd class="mt-0.5 text-zinc-800 dark:text-zinc-200">{{ $order->salesperson->name }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Confirmed by') }}</dt>
                    <dd class="mt-0.5 text-zinc-800 dark:text-zinc-200">{{ $order->confirmedBy?->name ?? '—' }} @if ($order->confirmed_at)<span class="font-data tabular-nums text-zinc-500">· {{ $order->confirmed_at->format('d M Y') }}</span>@endif</dd>
                </div>
            </dl>
            @if ($order->notes)
                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Notes') }}: “{{ $order->notes }}”</p>
            @endif
        </div>

        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 overflow-hidden flex flex-col">
            <div class="p-5 flex-1">
                <p class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Order Value') }}</p>
                <dl class="mt-3 space-y-2 text-xs">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Products subtotal') }}</dt>
                        <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatRupiah($order->itemsSubtotal()) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Discount') }}@if (bccomp((string) $order->discount_percent, '0', 2) > 0) <span class="font-data tabular-nums">({{ $this->formatQuantity((string) $order->discount_percent) }}%)</span>@endif</dt>
                        <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ bccomp((string) $order->discount_amount, '0', 2) > 0 ? '−'.$this->formatRupiah((string) $order->discount_amount) : '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Other charges') }}</dt>
                        <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $order->charges->isNotEmpty() ? $this->formatRupiah($order->chargesTotal()) : '—' }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Prices exclude tax — tax goes on the invoice.') }}</p>
            </div>
            <div class="px-5 py-4 bg-accent">
                <p class="text-[10px] font-semibold uppercase tracking-widest text-accent-foreground/70">{{ __('Total') }}</p>
                <p class="font-data text-xl font-medium tabular-nums text-accent-foreground mt-0.5">{{ $this->formatRupiah((string) $order->total_amount) }}</p>
                <x-tax-estimate :amount="(string) $order->total_amount" />
            </div>
        </div>
    </div>

    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;" x-data="{ tab: $wire.entangle('activeTab') }">
        <x-tab-bar :label="__('Order details')" :tabs="[
            'products' => ['label' => __('Products'), 'count' => $order->items->count()],
            'charges' => ['label' => __('Other charges'), 'count' => $order->charges->count()],
            'deliveries' => ['label' => __('Deliveries'), 'count' => $order->deliveries->count()],
        ]" />

        <div role="tabpanel" x-show="tab === 'products'" class="mt-4">
        <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Product') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Ordered') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Delivered') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Unit Price') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Subtotal') }}</th>
                        @if ($open)
                            <th class="py-2 px-3 text-right">{{ __('In Stock') }}</th>
                            <th class="py-2 px-3 text-right">{{ __('Incoming') }}</th>
                            <th class="py-2 px-3">{{ __('Status') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->lines as $line)
                        @php($item = $line['item'])
                        @php($unit = $item->product->unit_of_measure)
                        <tr wire:key="line-{{ $item->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                            <td class="py-2 px-3">
                                <p class="text-zinc-800 dark:text-zinc-200">{{ $item->product->product_name }}</p>
                                <p class="font-data text-[11px] text-accent">{{ $item->product->product_code }}</p>
                            </td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatQuantity((string) $item->quantity_ordered) }} {{ $unit }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap {{ $item->isFullyDelivered() ? 'text-green-600 dark:text-green-400' : 'text-zinc-600 dark:text-zinc-400' }}">{{ $this->formatQuantity((string) $item->quantity_delivered) }} {{ $unit }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $this->formatRupiah((string) $item->unit_price) }}</td>
                            <td class="py-2 px-3 text-right font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatRupiah((string) $item->subtotal) }}</td>
                            @if ($open)
                                <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap {{ $line['shortage'] ? 'text-red-600 dark:text-red-400 font-medium' : 'text-zinc-600 dark:text-zinc-400' }}">
                                    {{ $this->formatQuantity($line['stock']) }} {{ $unit }}
                                    @if (bccomp($line['promised'], '0', 2) > 0)
                                        <span class="block text-[10px] font-normal text-zinc-500 dark:text-zinc-400">{{ __(':qty for other orders', ['qty' => $this->formatQuantity($line['promised'])]) }}</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-right whitespace-nowrap">
                                    @if (bccomp($line['incoming'], '0', 2) > 0)
                                        <flux:modal.trigger name="incoming-{{ $item->product_id }}">
                                            <button type="button" class="group/in -my-1 inline-flex items-center gap-0.5 rounded px-1 py-1 font-data tabular-nums text-zinc-700 dark:text-zinc-300 underline decoration-dotted decoration-zinc-400 underline-offset-4 hover:text-accent hover:decoration-accent focus-visible:outline-2 focus-visible:outline-accent cursor-pointer transition-colors duration-150">
                                                {{ $this->formatQuantity($line['incoming']) }} {{ $unit }}
                                                <flux:icon.chevron-right variant="micro" class="size-3.5 text-zinc-400 group-hover/in:text-accent" />
                                            </button>
                                        </flux:modal.trigger>
                                    @else
                                        <span class="font-data text-zinc-400 dark:text-zinc-500">—</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 whitespace-nowrap">
                                    <x-material-status :status="$line['status']" :shortage="$line['shortage']" :unit="$unit" />
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        </div>

        <div role="tabpanel" x-show="tab === 'charges'" x-cloak class="mt-4">
            @if ($order->charges->isEmpty())
                <div class="rounded-md border border-dashed border-zinc-200 dark:border-white/10 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No other charges.') }}</div>
            @else
                <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach ($order->charges as $charge)
                        <div wire:key="charge-{{ $charge->id }}" class="flex items-center justify-between gap-3 px-4 py-3 text-xs">
                            <span class="text-zinc-800 dark:text-zinc-200">{{ $charge->description }}</span>
                            <span class="font-data tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $charge->amount) }}</span>
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between gap-3 px-4 py-3 text-xs bg-zinc-50 dark:bg-white/5">
                        <span class="font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</span>
                        <span class="font-data font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($order->chargesTotal()) }}</span>
                    </div>
                </div>
            @endif
        </div>

        <div role="tabpanel" x-show="tab === 'deliveries'" x-cloak class="mt-4">
        @if ($order->deliveries->isEmpty())
            <div class="rounded-md border border-dashed border-zinc-200 dark:border-white/10 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                {{ $order->status === SalesOrderStatus::Draft ? __('Confirm the order so the warehouse can deliver it.') : __('Nothing delivered yet.') }}
            </div>
        @else
            <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5">
                @foreach ($order->deliveries->sortByDesc('id') as $delivery)
                    <div wire:key="do-{{ $delivery->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="font-data text-zinc-800 dark:text-zinc-200">{{ $delivery->delivery_number }}</span>
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->documentBadgeClasses($delivery->status) }}">{{ $delivery->status->label() }}</span>
                            @if ($delivery->status === \App\Enums\DocumentStatus::Posted)
                                @php($salesInvoice = $delivery->salesInvoices->first(fn ($i) => $i->status !== \App\Enums\SalesInvoiceStatus::Cancelled))
                                @if ($salesInvoice)
                                    <span class="text-zinc-400">→</span>
                                    @can('sales.invoice.view')
                                        <a href="{{ route('accounting.sales-invoices.show', $salesInvoice) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $salesInvoice->invoice_number }}</a>
                                    @else
                                        <span class="font-data text-zinc-700 dark:text-zinc-300">{{ $salesInvoice->invoice_number }}</span>
                                    @endcan
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $salesInvoice->status->badgeClasses() }}">{{ $salesInvoice->status->label() }}</span>
                                @else
                                    <span class="text-[11px] text-zinc-500 dark:text-zinc-400">· {{ __('not invoiced yet') }}</span>
                                @endif
                            @endif
                        </div>
                        <span class="font-data tabular-nums text-zinc-500 dark:text-zinc-400">
                            {{ $this->formatQuantity((string) $delivery->items->sum('quantity')) }} {{ __('units') }} · {{ $delivery->delivered_at?->format('d M Y H:i') ?? __('not sent yet') }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
        </div>
    </div>

    @foreach ($this->lines as $line)
        @continue(! $open || bccomp($line['incoming'], '0', 2) <= 0)
        @php($unit = $line['item']->product->unit_of_measure)
        <flux:modal name="incoming-{{ $line['item']->product_id }}" class="max-w-xl">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Incoming') }} · {{ $line['item']->product->product_name }}</flux:heading>
                    <flux:subheading>{{ __('Expected stock from work orders still in production.') }}</flux:subheading>
                </div>
                <x-stock-source-list :rows="$this->incoming[$line['item']->product_id] ?? []" :unit="$unit" />
                <div class="flex justify-end">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Close') }}</flux:button></flux:modal.close>
                </div>
            </div>
        </flux:modal>
    @endforeach

    @can('confirm', $order)
        @php($shortLines = $this->lines->whereNotNull('shortage'))
        <flux:modal name="confirm-so" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Confirm this sales order?') }}</flux:heading>
                    <flux:subheading>{{ __('The order goes to the warehouse for delivery. It can no longer be edited.') }}</flux:subheading>
                </div>
                @if ($shortLines->isNotEmpty())
                    <div class="rounded-md border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 px-3 py-2.5">
                        <p class="flex items-center gap-1.5 text-xs font-semibold text-amber-800 dark:text-amber-300">
                            <flux:icon.exclamation-triangle variant="micro" class="size-3.5" />
                            {{ __('Not enough stock for :count product(s)', ['count' => $shortLines->count()]) }}
                        </p>
                        <ul class="mt-1.5 space-y-1">
                            @foreach ($shortLines as $line)
                                <li class="flex items-center justify-between gap-3 text-xs text-amber-800 dark:text-amber-300">
                                    <span>{{ $line['item']->product->product_name }}</span>
                                    <span class="font-data tabular-nums">{{ __('short :qty', ['qty' => $this->formatQuantity($line['shortage'])]) }} · {{ __('incoming') }} {{ $this->formatQuantity($line['incoming']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-[11px] text-amber-700 dark:text-amber-400">{{ __('You can still confirm it — production can make the rest. The warehouse cannot deliver more than what is in stock.') }}</p>
                    </div>
                @endif
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm" class="active:scale-[0.95]">{{ __('Confirm Order') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan

    @can('cancel', $order)
        <flux:modal name="cancel-so" :show="$errors->has('cancelReason')" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Cancel this sales order?') }}</flux:heading>
                    <flux:subheading>{{ __('Only possible while nothing has been delivered. A cancelled order cannot be reopened.') }}</flux:subheading>
                </div>
                <flux:field>
                    <flux:label>{{ __('Reason') }}</flux:label>
                    <flux:textarea wire:model="cancelReason" rows="3" :placeholder="__('e.g. customer cancelled the order')" />
                    <flux:error name="cancelReason" />
                </flux:field>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Keep it') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="cancelOrder" wire:loading.attr="disabled" wire:target="cancelOrder" class="active:scale-[0.95]">{{ __('Cancel Order') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan
</section>
