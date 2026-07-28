<?php

use App\Actions\Purchasing\CreatePurchaseOrderAction;
use App\DTO\Purchasing\CreatePurchaseOrderData;
use App\Exceptions\NonPurchasableProductException;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Create Purchase Order')] class extends Component {
    public string $supplierId = '';

    public string $orderDate = '';

    public ?string $expectedDeliveryDate = null;

    public array $items = [];

    public function mount(): void
    {
        Gate::authorize('create', PurchaseOrder::class);

        $this->orderDate = now()->toDateString();
        $this->addItemRow();
    }

    public function addItemRow(): void
    {
        $this->items[] = [
            'product_id' => '',
            'quantity_ordered' => '',
            'unit_price' => '',
        ];
    }

    public function removeItemRow(int $index): void
    {
        unset($this->items[$index]);

        $this->items = array_values($this->items);
    }

    public function updatedSupplierId(): void
    {
        foreach ($this->items as $index => $item) {
            $this->items[$index]['product_id'] = '';
        }
    }

    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::orderBy('supplier_name')->get();
    }

    #[Computed]
    public function selectedSupplier(): ?Supplier
    {
        return $this->supplierId ? Supplier::find($this->supplierId) : null;
    }

    public function updated(string $name, mixed $value): void
    {
        if (preg_match('/^items\.(\d+)\.product_id$/', $name, $matches)) {
            $index = (int) $matches[1];
            $product = $this->availableProducts->firstWhere('id', (int) $value);
            $this->items[$index]['unit_price'] = $product ? bcadd($product->pivot->price, '0', 0) : '';
        }
    }

    #[Computed]
    public function availableProducts(): Collection
    {
        return $this->selectedSupplier?->products()->purchasable()->get() ?? collect();
    }

    public function rowSubtotal(array $item): string
    {
        $quantity = filled($item['quantity_ordered'] ?? null) ? (string) $item['quantity_ordered'] : '0';
        $price = filled($item['unit_price'] ?? null) ? (string) $item['unit_price'] : '0';

        return bcmul($quantity, $price, 2);
    }

    #[Computed]
    public function estimatedTotal(): string
    {
        return collect($this->items)->reduce(
            fn (string $carry, array $item) => bcadd($carry, $this->rowSubtotal($item), 2),
            '0'
        );
    }

    #[Computed]
    public function totalQuantity(): string
    {
        return collect($this->items)->reduce(
            fn (string $carry, array $item) => bcadd($carry, filled($item['quantity_ordered'] ?? null) ? (string) $item['quantity_ordered'] : '0', 2),
            '0'
        );
    }

    public function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    protected function rules(): array
    {
        return [
            'supplierId' => ['required', 'exists:suppliers,id'],
            'orderDate' => ['required', 'date'],
            'expectedDeliveryDate' => ['nullable', 'date', 'after_or_equal:orderDate'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity_ordered' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'supplierId' => 'supplier',
            'orderDate' => 'order date',
            'expectedDeliveryDate' => 'expected delivery date',
            'items.*.product_id' => 'product',
            'items.*.quantity_ordered' => 'quantity',
            'items.*.unit_price' => 'unit price',
        ];
    }

    public function create(): void
    {
        Gate::authorize('create', PurchaseOrder::class);

        $this->items = array_values(array_filter($this->items, function (array $item): bool {
            return filled($item['product_id']) || filled($item['quantity_ordered']) || filled($item['unit_price']);
        }));

        $this->validate();

        $data = new CreatePurchaseOrderData(
            supplierId: $this->supplierId,
            orderDate: $this->orderDate,
            expectedDeliveryDate: $this->expectedDeliveryDate,
            createdBy: Auth::id(),
            items: $this->items,
        );

        try {
            app(CreatePurchaseOrderAction::class)->handle($data);
        } catch (NonPurchasableProductException $e) {
            $this->addError('items', $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Purchase order created.'));

        $this->redirect(route('purchasing.orders.list'), navigate: true);
    }
}; ?>

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('purchasing.dashboard')" wire:navigate>{{ __('Purchasing') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Create Purchase Order') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 shadow-md shadow-zinc-900/4 dark:shadow-none p-6 sm:p-8 lg:p-10">

        <div class="flex flex-wrap items-start justify-between gap-4 pb-6 mb-8 border-b border-zinc-200 dark:border-white/10">
            <div>
                <p class="font-mono text-[11px] font-semibold tracking-[0.2em] uppercase text-accent mb-2">
                    {{ __('Purchasing') }} &middot; {{ __('Order Draft') }}
                </p>
                <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                    {{ __('New Purchase Order') }}
                </h1>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                    {{ __('Opened') }} {{ now()->format('d M Y') }}
                </p>
            </div>

            <flux:button variant="primary" wire:click="create" wire:loading.attr="disabled" wire:target="create">
                {{ __('Save Purchase Order') }}
            </flux:button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-x-10 gap-y-10">
            {{-- Main column --}}
            <div class="lg:col-span-2 space-y-10">
                {{-- Vendor Information --}}
                <section>
                    <div class="flex items-center gap-2.5 pb-2.5 mb-5 border-b border-zinc-200 dark:border-white/10">
                        <span class="flex items-center justify-center size-5 rounded-sm bg-accent/10 dark:bg-accent/20 font-mono text-[10px] font-bold text-accent">1</span>
                        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">
                            {{ __('Supplier & Schedule') }}
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-5 gap-5">
                        <div class="md:col-span-3">
                            @if ($this->selectedSupplier)
                                <div class="rounded-md border border-zinc-200 dark:border-white/10 border-s-[3px] border-s-accent bg-zinc-50 dark:bg-white/[0.03] px-4 py-3.5">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="font-mono text-[10px] uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-1">
                                                {{ __('Supplier') }} &middot; {{ $this->selectedSupplier->supplier_code }}
                                            </p>
                                            <p class="font-display text-sm font-bold text-zinc-900 dark:text-white">
                                                {{ $this->selectedSupplier->supplier_name }}
                                            </p>
                                        </div>
                                        <flux:button size="sm" variant="ghost" wire:click="$set('supplierId', '')">
                                            {{ __('Change') }}
                                        </flux:button>
                                    </div>

                                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 mt-3 pt-3 border-t border-zinc-200/70 dark:border-white/10 text-xs">
                                        <div>
                                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Contact') }}</dt>
                                            <dd class="text-zinc-700 dark:text-zinc-300 mt-0.5">{{ $this->selectedSupplier->contact_person ?? '—' }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Phone') }}</dt>
                                            <dd class="text-zinc-700 dark:text-zinc-300 mt-0.5">{{ $this->selectedSupplier->phone ?? '—' }}</dd>
                                        </div>
                                        <div class="col-span-2">
                                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Address') }}</dt>
                                            <dd class="text-zinc-700 dark:text-zinc-300 mt-0.5">{{ $this->selectedSupplier->address ?? '—' }}</dd>
                                        </div>
                                    </dl>
                                </div>
                            @else
                                <div>
                                    <flux:select
                                        wire:model.live="supplierId"
                                        wire:loading.attr="disabled"
                                        wire:target="supplierId"
                                        :label="__('Supplier')"
                                        :placeholder="__('Select a registered supplier...')"
                                    >
                                        @foreach ($this->suppliers as $supplier)
                                            <option value="{{ $supplier->id }}">{{ $supplier->supplier_code }} — {{ $supplier->supplier_name }}</option>
                                        @endforeach
                                    </flux:select>

                                    <div wire:loading wire:target="supplierId" class="flex items-center gap-1.5 mt-1.5 text-xs text-accent">
                                        <flux:icon.loading variant="micro" class="size-3.5" />
                                        {{ __('Loading supplier details...') }}
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="md:col-span-2 grid grid-cols-1 gap-4">
                            <flux:input type="date" wire:model="orderDate" :label="__('Order Date')" />
                            <flux:input type="date" wire:model="expectedDeliveryDate" :label="__('Expected Delivery')" />
                        </div>
                    </div>
                </section>

                {{-- Line Item Specifications --}}
                <section>
                    <div class="flex items-center justify-between pb-2.5 mb-5 border-b border-zinc-200 dark:border-white/10">
                        <div class="flex items-center gap-2.5">
                            <span class="flex items-center justify-center size-5 rounded-sm bg-accent/10 dark:bg-accent/20 font-mono text-[10px] font-bold text-accent">2</span>
                            <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">
                                {{ __('Line Items') }}
                                <span class="text-zinc-400 dark:text-zinc-500 font-normal normal-case tracking-normal">
                                    ({{ str_pad((string) count($items), 2, '0', STR_PAD_LEFT) }})
                                </span>
                            </h2>
                        </div>

                        <flux:button size="sm" variant="ghost" icon="plus" wire:click="addItemRow" :disabled="!$supplierId">
                            {{ __('Add Row') }}
                        </flux:button>
                    </div>

                    <div wire:loading wire:target="supplierId" class="overflow-hidden rounded-md border border-zinc-200 dark:border-white/10">
                        <div class="animate-pulse motion-reduce:animate-none divide-y divide-zinc-100 dark:divide-white/5">
                            @for ($i = 0; $i < 3; $i++)
                                <div class="flex items-center gap-4 px-3 py-3.5">
                                    <div class="h-4 flex-1 rounded bg-zinc-200 dark:bg-white/10"></div>
                                    <div class="h-4 w-16 rounded bg-zinc-200 dark:bg-white/10"></div>
                                    <div class="h-4 w-24 rounded bg-zinc-200 dark:bg-white/10"></div>
                                    <div class="h-4 w-20 rounded bg-zinc-200 dark:bg-white/10"></div>
                                </div>
                            @endfor
                        </div>
                    </div>

                    <div wire:loading.remove wire:target="supplierId">
                    @if (!$supplierId)
                        <div class="flex flex-col items-center justify-center text-center py-10 rounded-md border border-dashed border-zinc-200 dark:border-white/10">
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Select a supplier first to add items.') }}</p>
                        </div>
                    @else
                        <div class="overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10">
                            <table class="w-full text-sm border-collapse">
                                <thead class="sticky top-0 z-10">
                                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                                        <th class="py-2.5 px-3">{{ __('Product') }}</th>
                                        <th class="py-2.5 px-3 text-right">{{ __('Qty') }}</th>
                                        <th class="py-2.5 px-3 text-right">{{ __('Unit Price') }}</th>
                                        <th class="py-2.5 px-3 text-right">{{ __('Subtotal') }}</th>
                                        <th class="py-2.5 px-3 w-9"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($items as $index => $item)
                                        <?php $product = $this->availableProducts->firstWhere('id', (int) ($item['product_id'] ?? 0)); ?>
                                        <tr wire:key="item-row-{{ $index }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/3 transition-colors align-top">
                                            <td class="py-2 px-3 min-w-48">
                                                {{-- TODO --}}
                                                <flux:select size="sm" wire:model.live="items.{{ $index }}.product_id" :placeholder="__('Select product...')">
                                                    @foreach ($this->availableProducts as $option)
                                                        <option value="{{ $option->id }}">{{ $option->product_code }} — {{ $option->product_name }}</option>
                                                    @endforeach
                                                </flux:select>
                                                @if ($product)
                                                    <p class="mt-1 pl-0.5 flex items-center justify-between gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                                        <span>
                                                            <span class="font-mono text-accent">{{ $product->product_code }} {{ $product->unit_of_measure }}</span>
                                                        </span>

                                                        @if ($product->isBelowReorderPoint())
                                                            <span class="inline-flex items-center mt-1 gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-1.5 py-0.5 font-mono tabular-nums text-red-700 dark:text-red-400">
                                                                <flux:icon.exclamation-triangle variant="micro" class="size-3" />
                                                                {{ __('Low') }} {{ $product->current_stock }}
                                                            </span>
                                                        @else
                                                            <span class="font-mono tabular-nums">{{ __('Stock') }} {{ $product->current_stock }}</span>
                                                        @endif
                                                    </p>
                                                @endif
                                                @error("items.$index.product_id") <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                                            </td>
                                            <td class="py-2 px-3 w-40">
                                                <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-mono tabular-nums" wire:model.live="items.{{ $index }}.quantity_ordered" :loading="false" />
                                                @error("items.$index.quantity_ordered") 
                                                <flux:error class="mt-1">{{ $message }}</flux:error> 
                                                @enderror
                                            </td>
                                            {{-- TODO --}}
                                            <td class="py-2 px-3 w-60">
                                                <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-mono tabular-nums" wire:model.live="items.{{ $index }}.unit_price" :loading="false" />
                                                @error("items.$index.unit_price") <flux:error class="mt-1">{{ $message }}</flux:error> @enderror
                                            </td>
                                            <td class="py-2 px-3 pt-3.5">
                                                <span
                                                    wire:loading.remove
                                                    wire:target="items.{{ $index }}.quantity_ordered,items.{{ $index }}.unit_price"
                                                    class="inline-flex items-center justify-end w-full whitespace-nowrap font-mono text-xs font-semibold text-zinc-800 dark:text-zinc-200 tabular-nums"
                                                >
                                                    {{ $this->formatRupiah($this->rowSubtotal($item)) }}
                                                </span>
                                                <span
                                                    wire:loading
                                                    wire:target="items.{{ $index }}.quantity_ordered,items.{{ $index }}.unit_price"
                                                    class="inline-flex items-center justify-end w-full"
                                                >
                                                    <flux:icon.loading variant="micro" class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                                                </span>
                                            </td>
                                            <td class="py-2 px-3 pt-2.5 text-right">
                                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeItemRow({{ $index }})" />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @error('items') <flux:error class="mt-3">{{ $message }}</flux:error> @enderror
                    @endif
                    </div>
                </section>
            </div>

            {{-- Order Summary rail --}}
            <div class="lg:col-span-1">
                <div class="lg:sticky lg:top-6">
                    <div class="rounded-md border border-zinc-200 dark:border-white/10 overflow-hidden">
                        <div class="px-4 py-3 border-b border-zinc-200 dark:border-white/10">
                            <h2 class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Order Summary') }}</h2>
                        </div>

                        <dl class="divide-y divide-zinc-100 dark:divide-white/5 text-sm">
                            <div class="flex items-center justify-between px-4 py-2.5">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Line Items') }}</dt>
                                <dd class="font-mono tabular-nums text-zinc-800 dark:text-zinc-200">{{ str_pad((string) count($items), 2, '0', STR_PAD_LEFT) }}</dd>
                            </div>
                            <div class="flex items-center justify-between px-4 py-2.5">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Total Qty') }}</dt>
                                <dd class="font-mono tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->totalQuantity }}</dd>
                            </div>
                        </dl>

                        <div class="px-4 py-4 bg-accent">
                            <p class="text-[10px] font-semibold uppercase tracking-widest text-accent-foreground/70">{{ __('Est. Total') }}</p>
                            <p class="font-mono text-lg font-bold tabular-nums text-accent-foreground mt-0.5">{{ $this->formatRupiah($this->estimatedTotal) }}</p>
                        </div>
                    </div>

                    <p class="text-xs text-zinc-500 dark:text-zinc-400 px-1 mt-3">
                        {{ __('Totals are estimates based on current line items and update as you edit the form.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
