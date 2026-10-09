<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\DTO\Purchasing\ReceivePurchaseOrderBatchData;
use App\Services\Purchasing\ReceivePurchaseOrderBatchService;
use App\Exceptions\PurchaseOrderReceiptBatchRequiresItemsException;
use App\Exceptions\PurchaseOrderReceiptBatchRequiresAttachmentException;
use App\Exceptions\PurchaseOrderNotReceivableException;
use Illuminate\Support\Collection;
use Livewire\WithFileUploads;
use Flux\Flux;

new #[Title('Create Item Receipt')] class extends Component
{
    use WithFileUploads;

    public ?PurchaseOrder $purchaseOrder = null;

    public string $selectedPurchaseOrderId = '';

    public array $items = [];

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $attachment = null;

    public function mount(?PurchaseOrder $purchaseOrder = null): void {
        Gate::authorize('warehouse.receive');

        if ($purchaseOrder) {
            $this->selectPurchaseOrder($purchaseOrder);
        }
    }

    protected function selectPurchaseOrder(PurchaseOrder $purchaseOrder): void {
        Gate::authorize('receive', $purchaseOrder);
        $this->purchaseOrder = $purchaseOrder->load(['supplier', 'createdBy', 'items.product']);
        $this->syncItemsFromModel();
    }

    public function updatedSelectedPurchaseOrderId(): void {
        if ($this->selectedPurchaseOrderId === '') {
            return;
        }

        $this->redirect(route('warehouse.inbound.item-receipts.create', ['purchaseOrder' => $this->selectedPurchaseOrderId]), navigate: true);
    }

    #[Computed]
    public function receivablePurchaseOrders(): Collection {
        return PurchaseOrder::query()
            ->whereIn('status', ['approved', 'partially_received'])
            ->with('supplier')
            ->orderBy('order_date', 'desc')
            ->get();
    }

    protected function syncItemsFromModel(): void
    {
        $this->items = [];

        foreach ($this->purchaseOrder->items as $item) {
            if ($item->isFullyReceived()) {
                continue;
            }

            $this->items[$item->id] = [
                'quantity' => '',
            ];
        }
    }

    public function removeAttachment(): void
    {
        $this->reset('attachment');
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
    public function receivingProgress(): array {
        $ordered  = $this->orderedQuantitySummary()->first() ?? '0';
        $received = $this->receivedQuantitySummary()->first() ?? '0';
        $unit     = $this->orderedQuantitySummary()->keys()->first();

        $percentage = bccomp($ordered, '0', 2) > 0
            ? (int) round(bcdiv(bcmul($received, '100', 4), $ordered, 4))
            : 0;

        return [
            'ordered'      => $ordered,
            'received'     => $received,
            'unit'         => $unit,
            'percentage'   => $percentage,
            'barPercentage' => min(100, $percentage), // clamped so the bar never overflows its track
        ];
    }

    #[Computed]
    public function itemStatusBreakdown(): array {
        $items = $this->purchaseOrder->items;

        $received = $items->filter->isFullyReceived()->count();
        $partial  = $items->filter(fn ($item) => !$item->isFullyReceived() && (float) $item->quantity_received > 0)->count();
        $total    = $items->count();

        return [
            'total'    => $total,
            'received' => $received,
            'partial'  => $partial,
            'pending'  => $total - $received - $partial,
        ];
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
            PurchaseOrderStatus::Closed => 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-500/10 dark:text-slate-400 dark:border-slate-500/20',
        };
    }

    public function submit(): void
    {
        Gate::authorize('receive', $this->purchaseOrder);

        $itemsToSubmit = collect($this->items)->filter(
            fn ($item) => $item['quantity'] !== '' && bccomp($item['quantity'], '0', 2) > 0
        );

        if ($itemsToSubmit->isEmpty()) {
            $message = __('At least one item must have a quantity greater than 0.');
            $this->addError('items', $message);
            Flux::toast(variant: 'danger', text: $message);
            return;
        }

        try {
            $this->validate(
                ['attachment' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg']],
                attributes: ['attachment' => __('Attachment')],
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            Flux::toast(variant: 'danger', text: __('Please fix the highlighted fields before submitting.'));
            throw $e;
        }

        $attachmentPath = $this->attachment->store('purchase-order-receipts', 'local');

        $data = new ReceivePurchaseOrderBatchData(
            purchaseOrderId: $this->purchaseOrder->id,
            receivedBy: Auth::id(),
            items: $itemsToSubmit->map(fn ($item, $itemId) => [
                'purchaseOrderItemId' => (int) $itemId,
                'quantityReceived' => $item['quantity'],
            ])->values()->all(),
            attachmentPath: $attachmentPath,
        );

        try {
            app(ReceivePurchaseOrderBatchService::class)->handle($data);
        } catch (PurchaseOrderReceiptBatchRequiresItemsException|PurchaseOrderReceiptBatchRequiresAttachmentException|PurchaseOrderNotReceivableException $e) {
            $this->addError('items', $e->userMessage());
            return;
        }

        Flux::toast(variant: 'success', text: __('Receipt submitted.'));

        $this->redirect(route('warehouse.inbound.item-receipts.list'), navigate: true);
    }
};
?>

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
    [x-cloak] { display: none !important; }
</style>

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('warehouse.dashboard')" wire:navigate>{{ __('Warehouse') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('warehouse.inbound.item-receipts.list')" wire:navigate>{{ __('Item Receipt') }}</flux:breadcrumbs.item>
        @if ($purchaseOrder)
            <flux:breadcrumbs.item>{{ $purchaseOrder->po_number }}</flux:breadcrumbs.item>
        @else
            <flux:breadcrumbs.item>{{ __('Create') }}</flux:breadcrumbs.item>
        @endif
    </flux:breadcrumbs>

    @if (! $purchaseOrder)
        {{-- Source picker — nothing selected yet --}}
        <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
            <div>
                <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                    {{ __('Create Item Receipt') }}
                </h1>
                <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            </div>

            <flux:button variant="ghost" icon="list-bullet" :href="route('warehouse.inbound.item-receipts.list')" wire:navigate class="active:scale-[0.95]">
                {{ __('View Receipt Status') }}
            </flux:button>
        </div>

        <div class="mt-6 rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-6 sm:p-8 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
            <div class="mb-6">
                <p class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">
                    {{ __('Source') }}
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="rounded-md border-2 border-accent bg-accent/5 p-4 text-left">
                        <div class="flex items-center gap-2">
                            <flux:icon.document-text class="size-5 text-accent" />
                            <span class="font-medium text-zinc-900 dark:text-white">{{ __('Purchase Order') }}</span>
                        </div>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Receive raw materials against an approved purchase order.') }}</p>
                    </div>

                    <div class="rounded-md border border-dashed border-zinc-300 dark:border-white/10 p-4 text-left opacity-50 cursor-not-allowed" title="{{ __('Work Orders are not available yet.') }}">
                        <div class="flex items-center gap-2">
                            <flux:icon.cog-6-tooth class="size-5 text-zinc-400" />
                            <span class="font-medium text-zinc-500 dark:text-zinc-400">{{ __('Work Order') }}</span>
                        </div>
                        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ __('Coming soon.') }}</p>
                    </div>
                </div>
            </div>

            <div
                x-data="{ open: false }"
                x-on:keydown.escape.window="open = false"
                class="relative"
            >
                <p class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">
                    {{ __('Select Purchase Order') }}
                </p>

                <button
                    type="button"
                    x-on:click="open = !open"
                    x-on:click.outside="open = false"
                    :aria-expanded="open"
                    aria-haspopup="listbox"
                    wire:loading.attr="disabled"
                    wire:target="selectedPurchaseOrderId"
                    class="w-full flex items-center justify-between gap-3 rounded-md border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 px-3.5 py-2.5 text-left shadow-sm shadow-zinc-900/5 dark:shadow-none hover:border-accent/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/50 transition-colors"
                >
                    <span class="text-sm text-zinc-400 dark:text-zinc-500">{{ __('Select a purchase order to receive...') }}</span>
                    <flux:icon.chevron-down class="size-4 shrink-0 text-zinc-400 transition-transform duration-150" x-bind:class="open && 'rotate-180'" />
                </button>

                <div
                    x-show="open"
                    x-transition.origin.top
                    x-cloak
                    role="listbox"
                    class="absolute z-20 mt-2 w-full max-h-80 overflow-y-auto rounded-md border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-1.5 space-y-0.5"
                >
                    @forelse ($this->receivablePurchaseOrders as $order)
                        <button
                            type="button"
                            role="option"
                            wire:key="po-option-{{ $order->id }}"
                            wire:click="$set('selectedPurchaseOrderId', '{{ $order->id }}')"
                            x-on:click="open = false"
                            class="w-full flex items-center justify-between gap-3 rounded-md px-3 py-2.5 text-left hover:bg-accent/5 dark:hover:bg-accent/10 focus:outline-none focus-visible:bg-accent/5 transition-colors"
                        >
                            <span class="flex flex-col min-w-0">
                                <span class="font-data text-sm font-medium text-accent">{{ $order->po_number }}</span>
                                <span class="text-xs text-zinc-600 dark:text-zinc-400 truncate">{{ $order->supplier->supplier_name }}</span>
                            </span>
                            <span class="shrink-0 inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->statusBadgeClasses($order->status) }}">
                                {{ $order->status->label() }}
                            </span>
                        </button>
                    @empty
                        <p class="px-3 py-4 text-center text-xs text-zinc-500 dark:text-zinc-400">{{ __('No purchase orders are currently awaiting receipt.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    @else

    @php($isLate = $purchaseOrder->expected_delivery_date?->isPast() && !$purchaseOrder->status->isTerminal())

    {{-- Header --}}
    <div class="mt-3 motion-safe:animate-fade-slide-up">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="font-data text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ $purchaseOrder->po_number }}
            </h1>
            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-medium {{ $this->statusBadgeClasses($purchaseOrder->status) }}">
                {{ $purchaseOrder->status->label() }}
            </span>
        </div>
        <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1.5">{{ $purchaseOrder->supplier->supplier_name }}</p>
    </div>

    {{-- PO Info --}}
    <div class="mt-4 rounded-md border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 shadow-sm shadow-zinc-900/5 dark:shadow-none overflow-hidden motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <dl class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 px-5 py-4 text-xs">
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
    @php($progress = $this->receivingProgress())
    @php($breakdown = $this->itemStatusBreakdown())
    <div class="mt-4 grid grid-cols-1 {{ auth()->user()->can('costs.view') ? 'lg:grid-cols-3' : 'lg:grid-cols-2' }} gap-4 items-stretch">
        <div class="rounded-md border border-zinc-200 dark:border-white/10 border-s-[3px] border-s-accent bg-white dark:bg-zinc-900 shadow-sm shadow-zinc-900/5 dark:shadow-none overflow-hidden flex flex-col motion-safe:animate-fade-slide-up" style="animation-delay: 60ms;">
            <div class="p-4 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-3">
                    {{ __('Receiving Summary') }}
                </p>

                <div class="flex items-end justify-between gap-4 mb-2">
                    <div>
                        <p class="text-[10px] text-zinc-500 dark:text-zinc-400">{{ __('Received') }} / {{ __('Ordered') }}</p>
                        <p class="font-data tabular-nums text-lg font-semibold text-zinc-900 dark:text-white mt-0.5">
                            {{ $this->formatQuantity($progress['received']) }}
                            <span class="text-zinc-400 dark:text-zinc-500 font-normal">/ {{ $this->formatQuantity($progress['ordered']) }} {{ $progress['unit'] }}</span>
                        </p>
                    </div>
                    <p class="font-data tabular-nums text-sm font-semibold {{ $progress['percentage'] >= 100 ? 'text-green-600 dark:text-green-400' : 'text-accent' }}">
                        {{ $progress['percentage'] }}%
                    </p>
                </div>

                <div
                    role="progressbar"
                    aria-valuenow="{{ $progress['percentage'] }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-label="{{ __('Receiving progress') }}"
                    class="h-2 w-full rounded-full bg-zinc-100 dark:bg-white/10 overflow-hidden"
                >
                    <div
                        class="h-full w-full origin-left rounded-full transition-transform duration-500 ease-out motion-reduce:transition-none {{ $progress['percentage'] >= 100 ? 'bg-green-500' : 'bg-accent' }}"
                        style="transform: scaleX({{ $progress['barPercentage'] / 100 }})"
                    ></div>
                </div>

                @if ($progress['percentage'] >= 100)
                    <p class="mt-2 flex items-center gap-1 text-xs text-green-600 dark:text-green-400 motion-safe:animate-fade-slide-up" style="animation-delay: 300ms;">
                        <flux:icon.check-circle variant="micro" class="size-3.5" />
                        {{ __('Fully received') }}
                    </p>
                @endif
            </div>
        </div>

        <div class="rounded-md border border-zinc-200 dark:border-white/10 border-s-[3px] border-s-accent bg-white dark:bg-zinc-900 shadow-sm shadow-zinc-900/5 dark:shadow-none overflow-hidden flex flex-col motion-safe:animate-fade-slide-up" style="animation-delay: 90ms;">
            <div class="p-4 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-3">
                    {{ __('Item Breakdown') }}
                </p>

                <p class="font-data tabular-nums text-lg font-semibold text-zinc-900 dark:text-white mb-2">
                    {{ $breakdown['received'] }}
                    <span class="text-zinc-400 dark:text-zinc-500 font-normal text-sm">/ {{ $breakdown['total'] }} {{ __('items fully received') }}</span>
                </p>

                <div class="h-2 w-full rounded-full overflow-hidden flex bg-zinc-100 dark:bg-white/10">
                    @if ($breakdown['received'] > 0)
                        <div class="h-full bg-green-500" style="width: {{ $breakdown['received'] / $breakdown['total'] * 100 }}%"></div>
                    @endif
                    @if ($breakdown['partial'] > 0)
                        <div class="h-full bg-amber-500" style="width: {{ $breakdown['partial'] / $breakdown['total'] * 100 }}%"></div>
                    @endif
                    @if ($breakdown['pending'] > 0)
                        <div class="h-full bg-zinc-300 dark:bg-zinc-600" style="width: {{ $breakdown['pending'] / $breakdown['total'] * 100 }}%"></div>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 mt-3 text-xs">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="size-1.5 rounded-full bg-green-500"></span>
                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Received') }} <span class="font-data tabular-nums text-zinc-900 dark:text-white font-medium">{{ $breakdown['received'] }}</span></span>
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="size-1.5 rounded-full bg-amber-500"></span>
                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Partial') }} <span class="font-data tabular-nums text-zinc-900 dark:text-white font-medium">{{ $breakdown['partial'] }}</span></span>
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="size-1.5 rounded-full bg-zinc-300 dark:bg-zinc-600"></span>
                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Pending') }} <span class="font-data tabular-nums text-zinc-900 dark:text-white font-medium">{{ $breakdown['pending'] }}</span></span>
                    </span>
                </div>
            </div>
        </div>

        @can('costs.view')
        <div class="rounded-md border border-zinc-200 dark:border-white/10 border-s-[3px] border-s-accent bg-white dark:bg-zinc-900 shadow-sm shadow-zinc-900/5 dark:shadow-none overflow-hidden flex flex-col motion-safe:animate-fade-slide-up" style="animation-delay: 120ms;">
            <div class="p-4 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-3">
                    {{ __('Order Value') }}
                </p>
                <dl class="space-y-1.5 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Line Items') }}</dt>
                        <dd class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ str_pad((string) $purchaseOrder->items->count(), 2, '0', STR_PAD_LEFT) }}</dd>
                    </div>
                </dl>
            </div>
            <div class="px-4 py-3.5 bg-accent mt-auto">
                <p class="text-[10px] font-semibold uppercase tracking-widest text-accent-foreground/70">{{ __('Total') }}</p>
                <p class="font-data text-xl font-medium tabular-nums text-accent-foreground mt-0.5">
                    {{ $this->formatRupiah((string) $purchaseOrder->total_amount) }}
                </p>
            </div>
        </div>
        @endcan
    </div>
    {{-- Items to Receive --}}
    <div class="mt-6 motion-safe:animate-fade-slide-up" style="animation-delay: 150ms;">
        <h2 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">
            <flux:icon.clipboard-document-list variant="micro" class="size-3.5 text-zinc-400 dark:text-zinc-500" />
            {{ __('Items List') }}
        </h2>
        @error('items')
            <p class="mb-3 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
        @enderror

        {{-- Desktop/tablet table --}}
        <div class="hidden lg:block overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10 shadow-sm shadow-zinc-900/5 dark:shadow-none">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Product') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Ordered') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Received') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Remaining') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Receive Now') }}</th>
                        <th class="py-2 px-3">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($purchaseOrder->items as $item)
                        <tr
                            wire:key="receive-item-{{ $item->id }}"
                            x-data
                            :class="$wire.items['{{ $item->id }}']?.quantity ? 'bg-accent/5 dark:bg-accent/10' : ''"
                            class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/3 transition-colors duration-200"
                        >
                            <td class="py-2.5 px-3">
                                <p class="font-data text-accent">{{ $item->product->product_code }}</p>
                                <p class="text-zinc-700 dark:text-zinc-300">{{ $item->product->product_name }}</p>
                            </td>
                            <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">
                                {{ $this->formatQuantity((string) $item->quantity_ordered) }} {{ $item->product->unit_of_measure }}
                            </td>
                            <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">
                                {{ $this->formatQuantity((string) $item->quantity_received) }} {{ $item->product->unit_of_measure }}
                            </td>
                            <td class="py-2.5 px-3 text-right font-data font-medium tabular-nums {{ $item->isFullyReceived() ? 'text-zinc-400 dark:text-zinc-500' : 'text-accent' }}">
                                {{ $this->formatQuantity($item->remainingQuantity()) }} {{ $item->product->unit_of_measure }}
                            </td>
                            <td class="py-2.5 px-3 w-48">
                                @if ($item->isFullyReceived())
                                    <p class="text-right text-zinc-400 dark:text-zinc-500 italic">&mdash;</p>
                                @else
                                    <flux:input.group>
                                        <flux:input
                                            wire:model="items.{{ $item->id }}.quantity"
                                            size="sm"
                                            type="text"
                                            inputmode="decimal"
                                            pattern="[0-9]*\.?[0-9]*"
                                            placeholder="0"
                                            input:class="text-right font-data tabular-nums transition-colors duration-150"
                                            aria-label="{{ __('Quantity received now for :product', ['product' => $item->product->product_name]) }}"
                                        />
                                        <flux:input.group.suffix>{{ $item->product->unit_of_measure }}</flux:input.group.suffix>
                                    </flux:input.group>
                                @endif
                            </td>
                            <td class="py-2.5 px-3">
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="size-1.5 rounded-full transition-colors duration-200 {{ $item->isFullyReceived() ? 'bg-green-500' : ((float) $item->quantity_received > 0 ? 'bg-amber-500' : 'bg-zinc-300 dark:bg-zinc-600') }}"></span>
                                    <span class="text-zinc-600 dark:text-zinc-400">
                                        {{ $item->isFullyReceived() ? __('Received') : ((float) $item->quantity_received > 0 ? __('Partial') : __('Pending')) }}
                                    </span>
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                                {{ __('No items on this purchase order.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile cards --}}
        <div class="lg:hidden space-y-3">
            @forelse ($purchaseOrder->items as $item)
                <div
                    wire:key="receive-item-mobile-{{ $item->id }}"
                    x-data
                    :class="$wire.items['{{ $item->id }}']?.quantity ? 'border-accent/40 bg-accent/5 dark:bg-accent/10' : 'border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900'"
                    class="rounded-md border p-3 shadow-sm shadow-zinc-900/5 dark:shadow-none transition-colors duration-200"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-data text-xs text-accent">{{ $item->product->product_code }}</p>
                            <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $item->product->product_name }}</p>
                        </div>
                        <span class="inline-flex items-center gap-1.5 shrink-0">
                            <span class="size-1.5 rounded-full {{ $item->isFullyReceived() ? 'bg-green-500' : ((float) $item->quantity_received > 0 ? 'bg-amber-500' : 'bg-zinc-300 dark:bg-zinc-600') }}"></span>
                            <span class="text-xs text-zinc-600 dark:text-zinc-400">
                                {{ $item->isFullyReceived() ? __('Received') : ((float) $item->quantity_received > 0 ? __('Partial') : __('Pending')) }}
                            </span>
                        </span>
                    </div>
                    <dl class="mt-3 space-y-1.5 text-xs">
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Ordered') }}</dt>
                            <dd class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity((string) $item->quantity_ordered) }} {{ $item->product->unit_of_measure }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Received') }}</dt>
                            <dd class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity((string) $item->quantity_received) }} {{ $item->product->unit_of_measure }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Remaining') }}</dt>
                            <dd class="font-data font-medium tabular-nums {{ $item->isFullyReceived() ? 'text-zinc-400 dark:text-zinc-500' : 'text-accent' }}">{{ $this->formatQuantity($item->remainingQuantity()) }} {{ $item->product->unit_of_measure }}</dd>
                        </div>
                    </dl>

                    @unless ($item->isFullyReceived())
                        <div class="mt-3">
                            <label class="block text-[10px] font-medium tracking-widest uppercase text-zinc-500 dark:text-zinc-400 mb-1">
                                {{ __('Receive Now') }}
                            </label>
                            <flux:input.group>
                                <flux:input
                                    wire:model="items.{{ $item->id }}.quantity"
                                    size="sm"
                                    type="text"
                                    inputmode="decimal"
                                    pattern="[0-9]*\.?[0-9]*"
                                    placeholder="0"
                                    input:class="text-right font-data tabular-nums transition-colors duration-150"
                                    aria-label="{{ __('Quantity received now for :product', ['product' => $item->product->product_name]) }}"
                                />
                                <flux:input.group.suffix>{{ $item->product->unit_of_measure }}</flux:input.group.suffix>
                            </flux:input.group>
                        </div>
                    @endunless
                </div>
            @empty
                <p class="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('No items on this purchase order.') }}
                </p>
            @endforelse
        </div>
    </div>

    {{-- Attachments --}}
    <div class="mt-6 motion-safe:animate-fade-slide-up" style="animation-delay: 180ms;">
        <h2 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">
            <flux:icon.paper-clip variant="micro" class="size-3.5 text-zinc-400 dark:text-zinc-500" />
            {{ __('Attachments') }}
        </h2>

        <div
            x-data="{ dragging: false }"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave.prevent="dragging = false"
            x-on:drop.prevent="
                dragging = false;
                const dt = new DataTransfer();
                dt.items.add($event.dataTransfer.files[0]);
                $refs.fileInput.files = dt.files;
                $refs.fileInput.dispatchEvent(new Event('change'));
            "
            :class="dragging ? 'border-accent bg-accent/5 scale-[1.01]' : ''"
            class="relative rounded-md border-2 border-dashed border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600 bg-zinc-50/50 dark:bg-white/2 px-4 py-8 text-center transition-all duration-150"
            @if ($attachment) style="display:none" @endif
        >
            <input
                type="file"
                x-ref="fileInput"
                wire:model="attachment"
                accept="image/*,.pdf"
                class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                aria-label="{{ __('Upload attachment') }}"
            />
            <flux:icon.arrow-up-tray class="mx-auto size-6 text-zinc-400 dark:text-zinc-500" wire:loading.class="animate-pulse" wire:target="attachment" />
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    <span class="font-medium text-accent">{{ __('Click to upload') }}</span> {{ __('or drag and drop') }}
                </p>
                <p class="mt-1 text-[11px] text-zinc-400 dark:text-zinc-500">{{ __('One delivery note or photo — PDF, PNG, JPG up to 10MB') }}</p>
        </div>
        @error('attachment') <p class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

        @if ($attachment)
            <div wire:key="attachment-selected" class="mt-3 flex items-center justify-between gap-3 rounded-md border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 px-3 py-2 text-xs">
                <div class="flex items-center gap-2 min-w-0">
                    <flux:icon.paper-clip class="size-4 shrink-0 text-zinc-400 dark:text-zinc-500" />
                    <span class="truncate text-zinc-700 dark:text-zinc-300">{{ $attachment->getClientOriginalName() }}</span>
                    <span class="shrink-0 text-zinc-400 dark:text-zinc-500">({{ number_format($attachment->getSize() / 1024, 0) }} KB)</span>
                </div>
                <button
                    type="button"
                    wire:click="removeAttachment"
                    class="shrink-0 p-1.5 text-zinc-400 hover:text-red-600 dark:hover:text-red-400 transition-colors cursor-pointer"
                    aria-label="{{ __('Remove') }} {{ $attachment->getClientOriginalName() }}"
                >
                    <flux:icon.x-mark class="size-3.5" />
                </button>
            </div>
        @endif
    </div>

    {{-- Actions --}}
    <div class="mt-6 flex flex-col-reverse sm:flex-row items-center justify-end gap-3 border-t border-zinc-100 dark:border-white/10 pt-4 motion-safe:animate-fade-slide-up" style="animation-delay: 240ms;">
        <flux:button variant="filled" :href="route('warehouse.inbound.item-receipts.list')" wire:navigate class="w-full sm:w-auto">
            {{ __('Cancel') }}
        </flux:button>
        <flux:button
            variant="primary"
            icon="check"
            type="button"
            wire:click="submit"
            wire:loading.attr="disabled"
            wire:target="submit"
            class="w-full sm:w-auto"
        >
            <span wire:loading.remove wire:target="submit">{{ __('Submit Receipt') }}</span>
            <span wire:loading wire:target="submit">{{ __('Submitting...') }}</span>
        </flux:button>
    </div>
    @endif
</section>