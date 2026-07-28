<?php

use App\Actions\Purchasing\AddPurchaseOrderItemAction;
use App\Actions\Purchasing\RemovePurchaseOrderItemAction;
use App\Actions\Purchasing\SubmitPurchaseOrderForApprovalAction;
use App\Actions\Purchasing\UpdatePurchaseOrderItemAction;
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
use Livewire\Component;

new #[Title('Purchase Order Details')] class extends Component {
    public PurchaseOrder $purchaseOrder;

    public array $items = [];

    public array $newItem = [
        'product_id' => '',
        'quantity_ordered' => '',
        'unit_price' => '',
    ];

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

    {{-- Header --}}
    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight font-mono text-zinc-900 dark:text-white leading-tight">
                    {{ $purchaseOrder->po_number }}
                </h1>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-medium {{ $this->statusBadgeClasses($purchaseOrder->status) }}">
                    {{ $purchaseOrder->status->label() }}
                </span>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ $purchaseOrder->supplier->supplier_name }}</p>
        </div>

        @can('submit', $purchaseOrder)
            <flux:button variant="primary" icon="paper-airplane" wire:click="submit" wire:loading.attr="disabled" wire:target="submit">
                {{ __('Submit for Approval') }}
            </flux:button>
        @endcan
    </div>

    @error('items') <flux:error class="mt-3">{{ $message }}</flux:error> @enderror
    @error('newItem') <flux:error class="mt-3">{{ $message }}</flux:error> @enderror

    {{-- Info cards --}}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="rounded-md border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">{{ __('Supplier') }}</p>
            <p class="font-mono text-xs text-accent">{{ $purchaseOrder->supplier->supplier_code }}</p>
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
                    <dd class="font-mono tabular-nums text-zinc-700 dark:text-zinc-300">{{ $purchaseOrder->order_date?->format('d M Y') }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Expected Delivery') }}</dt>
                    <dd class="font-mono tabular-nums text-zinc-700 dark:text-zinc-300">{{ $purchaseOrder->expected_delivery_date?->format('d M Y') ?? '—' }}</dd>
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

        <div class="rounded-md border border-accent/20 bg-accent/[0.04] dark:bg-accent/[0.06] p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-accent mb-2">{{ __('Order Summary') }}</p>
            <dl class="space-y-1.5 text-xs">
                <div class="flex justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Line Items') }}</dt>
                    <dd class="font-mono tabular-nums text-zinc-700 dark:text-zinc-300">{{ str_pad((string) $purchaseOrder->items->count(), 2, '0', STR_PAD_LEFT) }}</dd>
                </div>
            </dl>
            <div class="mt-3 pt-3 border-t border-accent/10">
                <p class="text-[10px] font-semibold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</p>
                <p class="font-mono text-xl font-bold tabular-nums text-zinc-900 dark:text-white mt-0.5">{{ $this->formatRupiah((string) $purchaseOrder->total_amount) }}</p>
            </div>
        </div>
    </div>

    {{-- Line items --}}
    <div class="mt-8">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">
            {{ __('Line Items') }}
        </h2>

        <div class="overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2.5 px-3">{{ __('Product') }}</th>
                        <th class="py-2.5 px-3 text-right">{{ __('Qty') }}</th>
                        <th class="py-2.5 px-3 text-right">{{ __('Unit Price') }}</th>
                        <th class="py-2.5 px-3 text-right">{{ __('Subtotal') }}</th>
                        <th class="py-2.5 px-3 text-right">{{ __('Received') }}</th>
                        @can('update', $purchaseOrder)
                            <th class="py-2.5 px-3 w-20"></th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($purchaseOrder->items as $item)
                        <tr wire:key="po-item-{{ $item->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 align-top">
                            <td class="py-2.5 px-3">
                                <p class="font-mono text-xs text-accent">{{ $item->product->product_code }}</p>
                                <p class="text-zinc-700 dark:text-zinc-300">{{ $item->product->product_name }}</p>
                            </td>
                            <td class="py-2.5 px-3 w-32">
                                @can('update', $purchaseOrder)
                                    <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-mono tabular-nums" wire:model="items.{{ $item->id }}.quantity_ordered" :loading="false" />
                                    @error("items.{$item->id}.quantity_ordered") <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                                @else
                                    <p class="text-right font-mono tabular-nums text-zinc-700 dark:text-zinc-300">{{ $item->quantity_ordered }}</p>
                                @endcan
                            </td>
                            <td class="py-2.5 px-3 w-40">
                                @can('update', $purchaseOrder)
                                    <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-mono tabular-nums" wire:model="items.{{ $item->id }}.unit_price" :loading="false"/>
                                    @error("items.{$item->id}.unit_price") <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                                @else
                                    <p class="text-right font-mono tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatRupiah((string) $item->unit_price) }}</p>
                                @endcan
                            </td>
                            <td class="py-2.5 px-3 pt-3.5 text-right font-mono font-semibold tabular-nums whitespace-nowrap text-zinc-800 dark:text-zinc-200">
                                {{ $this->formatRupiah((string) $item->subtotal) }}
                            </td>
                            <td class="py-2.5 px-3 pt-3.5 text-right">
                                <span class="inline-flex items-center justify-end gap-1.5 font-mono tabular-nums text-xs text-zinc-500 dark:text-zinc-400">
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
                            <td colspan="6" class="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                {{ __('No items on this purchase order yet.') }}
                            </td>
                        </tr>
                    @endforelse

                    @can('update', $purchaseOrder)
                        <tr class="bg-zinc-50/70 dark:bg-white/[0.02]">
                            <td class="py-2.5 px-3 align-top">
                                <flux:select size="sm" wire:model.live="newItem.product_id" :placeholder="__('Select product...')">
                                    @foreach ($this->availableProducts as $option)
                                        <option value="{{ $option->id }}">{{ $option->product_code }} — {{ $option->product_name }}</option>
                                    @endforeach
                                </flux:select>
                                @error('newItem.product_id') <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                            </td>
                            <td class="py-2.5 px-3 w-32 align-top">
                                <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-mono tabular-nums" wire:model="newItem.quantity_ordered" :loading="false" />
                                @error('newItem.quantity_ordered') <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                            </td>
                            <td class="py-2.5 px-3 w-40 align-top">
                                <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-mono tabular-nums" wire:model.live="newItem.unit_price" :loading="false" />
                                @error('newItem.unit_price') <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                            </td>
                            <td class="py-2.5 px-3"></td>
                            <td class="py-2.5 px-3"></td>
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
    </div>
</section>
