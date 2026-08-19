<?php

use App\Actions\Purchasing\AddPurchaseOrderItemAction;
use App\Actions\Purchasing\RemovePurchaseOrderItemAction;
use App\Actions\Purchasing\SubmitPurchaseOrderForApprovalAction;
use App\Actions\Purchasing\UpdatePurchaseOrderItemAction;
use App\Actions\Purchasing\ApprovePurchaseOrderAction;
use App\Actions\Purchasing\RejectPurchaseOrderAction;
use App\Actions\Purchasing\ReopenPurchaseOrderAction;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\NonPurchasableProductException;
use App\Exceptions\PurchaseOrderHaveNoItemException;
use App\Exceptions\PurchaseOrderItemQuantityNotValidException;
use App\Exceptions\PurchaseOrderNotEditableException;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new #[Title('Purchase Order Details')] class extends Component {
    public PurchaseOrder $purchaseOrder;

    public array $items = [];

    public array $newItem = [
        'product_id' => '',
        'quantity_ordered' => '',
        'unit_price' => '',
    ];

    public string $rejectionReason = '';

    public function mount(PurchaseOrder $purchaseOrder): void
    {
        Gate::authorize('view', $purchaseOrder);

        $this->purchaseOrder = $purchaseOrder->load(['supplier', 'createdBy', 'approvedBy', 'items.product']);

        $this->syncItemsFromModel();
    }

    protected function syncItemsFromModel(): void
    {
        $this->items = [];

        foreach ($this->purchaseOrder->items as $item) {
            $this->items[$item->id] = [
                'quantity_ordered' => (string) $item->quantity_ordered,
                'unit_price' => (int) $item->unit_price,
            ];
        }
    }

    #[Computed]
    public function availableProducts(): Collection
    {
        return $this->purchaseOrder->supplier->products()->purchasable()->get();
    }

    public function updated(string $name, mixed $value): void
    {
        if (preg_match('/^newItem\.product_id$/', $name, $matches)) {
            $product = $this->availableProducts->firstWhere('id', (int) $value);
            $this->newItem['unit_price'] = $product ? bcadd($product->pivot->price, '0', 0) : '';
        }
    }

    public function addItem(): void
    {
        Gate::authorize('update', $this->purchaseOrder);

        $this->validate([
            'newItem.product_id' => ['required', 'exists:products,id'],
            'newItem.quantity_ordered' => ['required', 'numeric', 'gt:0'],
            'newItem.unit_price' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            app(AddPurchaseOrderItemAction::class)->handle(
                po: $this->purchaseOrder,
                productId: (int) $this->newItem['product_id'],
                quantityOrdered: $this->newItem['quantity_ordered'],
                unitPrice: $this->newItem['unit_price'],
            );
        } catch (NonPurchasableProductException $e) {
            $this->addError('newItem.product_id', $e->userMessage());

            return;
        } catch (PurchaseOrderNotEditableException $e) {
            $this->addError('newItem', $e->userMessage());

            return;
        }

        $this->purchaseOrder->refresh()->load('items.product');
        $this->syncItemsFromModel();
        $this->newItem = ['product_id' => '', 'quantity_ordered' => '', 'unit_price' => ''];

        Flux::toast(variant: 'success', text: __('Item added.'));
    }

    public function updateItem(int $itemId): void
    {
        Gate::authorize('update', $this->purchaseOrder);

        $this->validate([
            "items.{$itemId}.quantity_ordered" => ['required', 'numeric', 'gt:0'],
            "items.{$itemId}.unit_price" => ['required', 'numeric', 'gt:0'],
        ]);

        $item = $this->purchaseOrder->items->firstWhere('id', $itemId);

        try {
            app(UpdatePurchaseOrderItemAction::class)->handle(
                item: $item,
                quantityOrdered: $this->items[$itemId]['quantity_ordered'],
                unitPrice: $this->items[$itemId]['unit_price'],
            );
        } catch (PurchaseOrderNotEditableException $e) {
            $this->addError('items', $e->userMessage());

            return;
        }

        $this->purchaseOrder->refresh()->load('items.product');
        $this->syncItemsFromModel();

        Flux::toast(variant: 'success', text: __('Item updated.'));
    }

    public function removeItem(int $itemId): void
    {
        Gate::authorize('update', $this->purchaseOrder);

        $item = PurchaseOrderItem::findOrFail($itemId);

        try {
            app(RemovePurchaseOrderItemAction::class)->handle($item);
        } catch (PurchaseOrderNotEditableException $e) {
            $this->addError('items', $e->userMessage());

            return;
        }

        $this->purchaseOrder->refresh()->load('items.product');
        $this->syncItemsFromModel();

        Flux::toast(variant: 'success', text: __('Item removed.'));
    }

    public function submit(): void
    {
        Gate::authorize('submit', $this->purchaseOrder);

        try {
            app(SubmitPurchaseOrderForApprovalAction::class)->handle($this->purchaseOrder);
        } catch (PurchaseOrderHaveNoItemException|PurchaseOrderItemQuantityNotValidException $e) {
            $this->addError('items', $e->userMessage());
            return;
        }

        Flux::toast(variant: 'success', text: __('Purchase order submitted for approval.'));

        $this->redirect(route('purchasing.orders.list'), navigate: true);
    }

    public function approve(): void
    {
        Gate::authorize('approve', $this->purchaseOrder);
        app(ApprovePurchaseOrderAction::class)->handle($this->purchaseOrder, Auth::id());
        $this->purchaseOrder->refresh();

        Flux::toast(variant: 'success', text: __('Purchase order approved.'));
    }
    public function reject(): void
    {
        Gate::authorize('reject', $this->purchaseOrder);

        $this->validate([
            'rejectionReason' => ['required', 'string', 'max:1000'],
        ]);

        app(RejectPurchaseOrderAction::class)->handle($this->purchaseOrder, Auth::id(), $this->rejectionReason);

        $this->purchaseOrder->refresh();
        $this->rejectionReason = '';
        $this->modal('reject-po')->close();

        Flux::toast(variant: 'success', text: __('Purchase order rejected.'));
    }

    public function reopen(): void
    {
        Gate::authorize('open', $this->purchaseOrder);

        app(ReopenPurchaseOrderAction::class)->handle($this->purchaseOrder);

        $this->purchaseOrder->refresh();

        Flux::toast(variant: 'success', text: __('Purchase order moved back to Draft.'));
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
        <flux:breadcrumbs.item :href="route('purchasing.orders.list')" wire:navigate>{{ __('Purchase Orders') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $purchaseOrder->po_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    @php($isLate = $purchaseOrder->expected_delivery_date?->isPast() && !$purchaseOrder->status->isTerminal())
    @php($newItemProduct = $this->availableProducts->firstWhere('id', (int) ($newItem['product_id'] ?? 0)))

    {{-- Header --}}
    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="font-mono text-[11px] font-semibold tracking-[0.2em] uppercase text-accent mb-2">
                {{ __('Purchasing') }} &middot; {{ __('Order Detail') }}
            </p>
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

        <div class="flex items-center gap-2">
            @can('reject', $purchaseOrder)
                <flux:modal.trigger name="reject-po">
                    <flux:button
                        variant="ghost"
                        icon="x-mark"
                        class="text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                    >
                        {{ __('Reject') }}
                    </flux:button>
                </flux:modal.trigger>
            @endcan

            @can('open', $purchaseOrder)
                <flux:button variant="filled" icon="arrow-uturn-left" wire:click="reopen" wire:loading.attr="disabled" wire:target="reopen">
                    {{ __('Move to Draft') }}
                </flux:button>
            @endcan

            @can('approve', $purchaseOrder)
                <flux:button variant="primary" icon="check" wire:click="approve" wire:loading.attr="disabled" wire:target="approve">
                    {{ __('Approve') }}
                </flux:button>
            @endcan

            @can('submit', $purchaseOrder)
                <flux:button variant="primary" icon="paper-airplane" wire:click="submit" wire:loading.attr="disabled" wire:target="submit">
                    {{ __('Submit for Approval') }}
                </flux:button>
            @endcan
        </div>
    </div>

    @can('reject', $purchaseOrder)
        <flux:modal name="reject-po" :show="$errors->has('rejectionReason')" focusable class="max-w-lg">
            <form wire:submit="reject" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Reject this purchase order?') }}</flux:heading>
                    <flux:subheading>
                        {{ __('It will need to be resubmitted from Draft before it can be approved again. Please explain why it\'s being rejected.') }}
                    </flux:subheading>
                </div>

                <flux:textarea wire:model="rejectionReason" :label="__('Rejection reason')" rows="4" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="danger" type="submit" wire:loading.attr="disabled" wire:target="reject">
                        {{ __('Reject Purchase Order') }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan

    @error('items') <flux:error class="mt-3">{{ $message }}</flux:error> @enderror
    @error('newItem') <flux:error class="mt-3">{{ $message }}</flux:error> @enderror

    {{-- Info cards --}}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="rounded-md border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">{{ __('Supplier') }}</p>
            <p class="font-data text-xs text-accent">{{ $purchaseOrder->supplier->supplier_code }}</p>
            <p class="font-display text-sm font-bold text-zinc-900 dark:text-white mt-0.5">{{ $purchaseOrder->supplier->supplier_name }}</p>
            <dl class="mt-3 pt-3 border-t border-zinc-100 dark:border-white/10 space-y-1.5 text-xs">
                <div class="flex justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Contact') }}</dt>
                    <dd class="text-zinc-700 dark:text-zinc-300 text-right">{{ $purchaseOrder->supplier->contact_person ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Phone') }}</dt>
                    <dd class="text-zinc-700 dark:text-zinc-300 text-right">{{ $purchaseOrder->supplier->phone ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-md border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">{{ __('Schedule') }}</p>
            <dl class="space-y-1.5 text-xs">
                <div class="flex justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Order Date') }}</dt>
                    <dd class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $purchaseOrder->order_date?->format('d M Y') }}</dd>
                </div>
                <div class="flex justify-between items-center gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Expected Delivery') }}</dt>
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
                <div class="flex justify-between gap-2 pt-1.5 mt-1.5 border-t border-zinc-100 dark:border-white/10">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Created By') }}</dt>
                    <dd class="text-zinc-700 dark:text-zinc-300">{{ $purchaseOrder->createdBy->name }}</dd>
                </div>
                @if ($purchaseOrder->approvedBy)
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Approved By') }}</dt>
                        <dd class="text-zinc-700 dark:text-zinc-300">{{ $purchaseOrder->approvedBy->name }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <div class="rounded-md border border-zinc-200 dark:border-white/10 border-s-[3px] border-s-accent bg-white dark:bg-zinc-900 overflow-hidden flex flex-col h-full">
            <div class="p-4 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">{{ __('Order Summary') }}</p>
                <dl class="space-y-1.5 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Line Items') }}</dt>
                        <dd class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ str_pad((string) $purchaseOrder->items->count(), 2, '0', STR_PAD_LEFT) }}</dd>
                    </div>
                </dl>
            </div>
            <div class="px-4 py-3.5 bg-accent mt-auto">
                <p class="text-[10px] font-semibold uppercase tracking-widest text-accent-foreground/70">{{ __('Total') }}</p>
                <p class="font-data text-xl font-medium tabular-nums text-accent-foreground mt-0.5">{{ $this->formatRupiah((string) $purchaseOrder->total_amount) }}</p>
            </div>
        </div>
    </div>

    {{-- Line items --}}
    <div class="mt-8">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">
            {{ __('Line Items') }}
        </h2>

        {{-- Desktop/tablet table --}}
        <div class="hidden lg:block overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Product') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Qty') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Unit Price') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Subtotal') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Received') }}</th>
                        @can('update', $purchaseOrder)
                            <th class="py-2 px-3 w-20"></th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($purchaseOrder->items as $item)
                        <tr wire:key="po-item-{{ $item->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 align-top">
                            <td class="py-2 px-3">
                                <p class="font-data text-accent">{{ $item->product->product_code }}</p>
                                <p class="text-zinc-700 dark:text-zinc-300">{{ $item->product->product_name }}</p>
                                @if ($item->product->isBelowReorderPoint())
                                    <span class="inline-flex items-center mt-1 gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-1.5 py-0.5 font-data tabular-nums text-[11px] text-red-700 dark:text-red-400">
                                        <flux:icon.exclamation-triangle variant="micro" class="size-3" />
                                        {{ __('Low') }} {{ $item->product->current_stock }}
                                    </span>
                                @else
                                    <span class="mt-1 block font-data tabular-nums text-zinc-500 dark:text-zinc-400">{{ __('Stock') }} {{ $item->product->current_stock }}</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 w-32">
                                @can('update', $purchaseOrder)
                                    <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-data tabular-nums" wire:model="items.{{ $item->id }}.quantity_ordered" :loading="false" />
                                    @error("items.{$item->id}.quantity_ordered") <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                                @else
                                    <p class="text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $item->quantity_ordered }}</p>
                                @endcan
                            </td>
                            <td class="py-2 px-3 w-40">
                                @can('update', $purchaseOrder)
                                    <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-data tabular-nums" wire:model="items.{{ $item->id }}.unit_price" :loading="false"/>
                                    @error("items.{$item->id}.unit_price") <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                                @else
                                    <p class="text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatRupiah((string) $item->unit_price) }}</p>
                                @endcan
                            </td>
                            <td class="py-2 px-3 pt-3 text-right font-data font-medium tabular-nums whitespace-nowrap text-zinc-900 dark:text-white">
                                {{ $this->formatRupiah((string) $item->subtotal) }}
                            </td>
                            <td class="py-2 px-3 pt-3 text-right">
                                <span class="inline-flex items-center justify-end gap-1.5 font-data tabular-nums text-zinc-500 dark:text-zinc-400">
                                    <span class="size-1.5 rounded-full {{ $item->isFullyReceived() ? 'bg-green-500' : ((float) $item->quantity_received > 0 ? 'bg-amber-500' : 'bg-zinc-300 dark:bg-zinc-600') }}"></span>
                                    {{ $item->quantity_received }} / {{ $item->quantity_ordered }}
                                </span>
                            </td>
                            @can('update', $purchaseOrder)
                                <td class="py-2 px-3 pt-2 text-right whitespace-nowrap">
                                    <flux:button size="sm" variant="ghost" icon="check" wire:click="updateItem({{ $item->id }})" wire:loading.attr="disabled" wire:target="updateItem({{ $item->id }})" />
                                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeItem({{ $item->id }})" wire:loading.attr="disabled" wire:target="removeItem({{ $item->id }})" />
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                                {{ __('No items on this purchase order yet.') }}
                            </td>
                        </tr>
                    @endforelse

                    @can('update', $purchaseOrder)
                        <tr class="bg-zinc-50/70 dark:bg-white/[0.02]">
                            <td class="py-2 px-3 align-top">
                                <flux:select size="sm" wire:model.live="newItem.product_id" :placeholder="__('Select product...')">
                                    @foreach ($this->availableProducts as $option)
                                        <option value="{{ $option->id }}">{{ $option->product_code }} — {{ $option->product_name }}</option>
                                    @endforeach
                                </flux:select>
                                @if ($newItemProduct)
                                    <p class="mt-1 pl-0.5 flex items-center justify-between gap-1.5 text-zinc-500 dark:text-zinc-400">
                                        <span>
                                            <span class="font-data text-accent">{{ $newItemProduct->product_code }} {{ $newItemProduct->unit_of_measure }}</span>
                                        </span>

                                        @if ($newItemProduct->isBelowReorderPoint())
                                            <span class="inline-flex items-center mt-1 gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-1.5 py-0.5 font-data tabular-nums text-red-700 dark:text-red-400">
                                                <flux:icon.exclamation-triangle variant="micro" class="size-3" />
                                                {{ __('Low') }} {{ $newItemProduct->current_stock }}
                                            </span>
                                        @else
                                            <span class="font-data tabular-nums">{{ __('Stock') }} {{ $newItemProduct->current_stock }}</span>
                                        @endif
                                    </p>
                                @endif
                                @error('newItem.product_id') <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                            </td>
                            <td class="py-2 px-3 w-32 align-top">
                                <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-data tabular-nums" wire:model="newItem.quantity_ordered" :loading="false" />
                                @error('newItem.quantity_ordered') <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                            </td>
                            <td class="py-2 px-3 w-40 align-top">
                                <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-data tabular-nums" wire:model.live="newItem.unit_price" :loading="false" />
                                @error('newItem.unit_price') <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                            </td>
                            <td class="py-2 px-3"></td>
                            <td class="py-2 px-3"></td>
                            <td class="py-2 px-3 text-right align-top">
                                <flux:button size="sm" variant="ghost" icon="plus" wire:click="addItem" wire:loading.attr="disabled" wire:target="addItem">
                                    {{ __('Add') }}
                                </flux:button>
                            </td>
                        </tr>
                    @endcan
                </tbody>
            </table>
        </div>

        {{-- Mobile/narrow-tablet card list — the table's inline-edit columns don't
             fit below `lg` without horizontal scroll, so items stack vertically
             with the same editable fields instead. --}}
        <div class="mt-4 lg:hidden rounded-md border border-zinc-200 dark:border-white/10 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
            @forelse ($purchaseOrder->items as $item)
                <div wire:key="po-item-card-{{ $item->id }}" class="p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-data text-xs text-accent">{{ $item->product->product_code }}</p>
                            <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $item->product->product_name }}</p>
                        </div>
                        @can('update', $purchaseOrder)
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeItem({{ $item->id }})" wire:loading.attr="disabled" wire:target="removeItem({{ $item->id }})" />
                        @endcan
                    </div>

                    @if ($item->product->isBelowReorderPoint())
                        <span class="inline-flex items-center mt-1.5 gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-1.5 py-0.5 font-data tabular-nums text-[11px] text-red-700 dark:text-red-400">
                            <flux:icon.exclamation-triangle variant="micro" class="size-3" />
                            {{ __('Low') }} {{ $item->product->current_stock }}
                        </span>
                    @else
                        <span class="mt-1.5 block font-data tabular-nums text-xs text-zinc-500 dark:text-zinc-400">{{ __('Stock') }} {{ $item->product->current_stock }}</span>
                    @endif

                    @can('update', $purchaseOrder)
                        <div class="grid grid-cols-2 gap-3 mt-3">
                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" :label="__('Qty')" input:class="font-data tabular-nums" wire:model="items.{{ $item->id }}.quantity_ordered" :loading="false" />
                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" :label="__('Unit Price')" input:class="font-data tabular-nums" wire:model="items.{{ $item->id }}.unit_price" :loading="false" />
                        </div>
                        @error("items.{$item->id}.quantity_ordered") <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                        @error("items.{$item->id}.unit_price") <flux:error class="mt-1">{{ $message }}</flux:error> @enderror

                        <div class="flex items-center justify-between gap-2 mt-3 pt-3 border-t border-zinc-100 dark:border-white/10 text-xs">
                            <span class="inline-flex items-center gap-1.5 font-data tabular-nums text-zinc-500 dark:text-zinc-400">
                                <span class="size-1.5 rounded-full {{ $item->isFullyReceived() ? 'bg-green-500' : ((float) $item->quantity_received > 0 ? 'bg-amber-500' : 'bg-zinc-300 dark:bg-zinc-600') }}"></span>
                                {{ __('Received') }} {{ $item->quantity_received }} / {{ $item->quantity_ordered }}
                            </span>
                            <span class="font-data font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $item->subtotal) }}</span>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="check" class="w-full mt-2" wire:click="updateItem({{ $item->id }})" wire:loading.attr="disabled" wire:target="updateItem({{ $item->id }})">
                            {{ __('Save changes') }}
                        </flux:button>
                    @else
                        <dl class="mt-3 pt-3 border-t border-zinc-100 dark:border-white/10 space-y-1.5 text-xs">
                            <div class="flex justify-between gap-2">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Qty') }}</dt>
                                <dd class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $item->quantity_ordered }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Unit Price') }}</dt>
                                <dd class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatRupiah((string) $item->unit_price) }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Received') }}</dt>
                                <dd class="inline-flex items-center gap-1.5 font-data tabular-nums text-zinc-700 dark:text-zinc-300">
                                    <span class="size-1.5 rounded-full {{ $item->isFullyReceived() ? 'bg-green-500' : ((float) $item->quantity_received > 0 ? 'bg-amber-500' : 'bg-zinc-300 dark:bg-zinc-600') }}"></span>
                                    {{ $item->quantity_received }} / {{ $item->quantity_ordered }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-2 pt-1.5 mt-1.5 border-t border-zinc-100 dark:border-white/10">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Subtotal') }}</dt>
                                <dd class="font-data font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $item->subtotal) }}</dd>
                            </div>
                        </dl>
                    @endcan
                </div>
            @empty
                <div class="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400 px-4">
                    {{ __('No items on this purchase order yet.') }}
                </div>
            @endforelse

            @can('update', $purchaseOrder)
                <div class="p-4 bg-zinc-50/70 dark:bg-white/[0.02]">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">{{ __('Add Item') }}</p>

                    <flux:select size="sm" wire:model.live="newItem.product_id" :placeholder="__('Select product...')">
                        @foreach ($this->availableProducts as $option)
                            <option value="{{ $option->id }}">{{ $option->product_code }} — {{ $option->product_name }}</option>
                        @endforeach
                    </flux:select>
                    @if ($newItemProduct)
                        <p class="mt-1 pl-0.5 flex items-center justify-between gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="font-data text-accent">{{ $newItemProduct->product_code }} {{ $newItemProduct->unit_of_measure }}</span>
                            @if ($newItemProduct->isBelowReorderPoint())
                                <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-1.5 py-0.5 font-data tabular-nums text-red-700 dark:text-red-400">
                                    <flux:icon.exclamation-triangle variant="micro" class="size-3" />
                                    {{ __('Low') }} {{ $newItemProduct->current_stock }}
                                </span>
                            @else
                                <span class="font-data tabular-nums">{{ __('Stock') }} {{ $newItemProduct->current_stock }}</span>
                            @endif
                        </p>
                    @endif
                    @error('newItem.product_id') <flux:error class="mt-1">{{ $message }}</flux:error> @enderror

                    <div class="grid grid-cols-2 gap-3 mt-3">
                        <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" :label="__('Qty')" input:class="font-data tabular-nums" wire:model="newItem.quantity_ordered" :loading="false" />
                        <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" :label="__('Unit Price')" input:class="font-data tabular-nums" wire:model.live="newItem.unit_price" :loading="false" />
                    </div>
                    @error('newItem.quantity_ordered') <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                    @error('newItem.unit_price') <flux:error class="mt-1">{{ $message }}</flux:error> @enderror

                    <flux:button size="sm" variant="primary" icon="plus" class="w-full mt-3" wire:click="addItem" wire:loading.attr="disabled" wire:target="addItem">
                        {{ __('Add Item') }}
                    </flux:button>
                </div>
            @endcan
        </div>
    </div>
</section>
