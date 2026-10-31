<?php

use App\Actions\Purchasing\CreatePurchaseOrderAction;
use App\DTO\Purchasing\CreatePurchaseOrderData;
use App\Exceptions\InvalidDeliveryDateException;
use App\Exceptions\NonPurchasableProductException;
use App\Exceptions\InvalidPurchaseRequisitionException;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Create Purchase Order')] class extends Component {
    public string $supplierId = '';

    #[Locked]
    public string $orderDate = '';

    public ?string $expectedDeliveryDate = null;

    public array $items = [];

    public array $charges = [];

    public string $activeTab = 'items';

    #[Locked]
    public array $requisitionIds = [];

    #[Locked]
    public array $requestedLines = [];

    public function mount(): void
    {
        Gate::authorize('create', PurchaseOrder::class);

        $this->orderDate = now()->toDateString();

        $ids = collect(explode(',', (string) request()->query('requisitions', '')))->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isNotEmpty() && Gate::allows('requisitions.process')) {
            $open = PurchaseRequisition::whereIn('id', $ids)->where('status', 'submitted')->with('items.purchaseOrder')->get();
            if ($open->count() < $ids->count()) {
                Flux::toast(variant: 'warning', text: __('Some selected requests are no longer open and were left out.'));
            }
            $this->requisitionIds = $open->pluck('id')->all();
            foreach ($open->flatMap->items as $item) {
                if ($item->isFulfilled()) {
                    continue;
                }
                $this->requestedLines[$item->product_id] = bcadd($this->requestedLines[$item->product_id] ?? '0', (string) $item->quantity, 2);
            }

            $prefillSupplierId = (int) request()->query('supplier', 0);
            if ($prefillSupplierId && Supplier::find($prefillSupplierId)) {
                $this->supplierId = (string) $prefillSupplierId;
                foreach ($this->availableProducts as $product) {
                    if (isset($this->requestedLines[$product->id])) {
                        $this->items[] = [
                            'product_id' => (string) $product->id,
                            'quantity_ordered' => \App\Support\Decimal::plain($this->requestedLines[$product->id]),
                            'unit_price' => $product->pivot->price !== null ? bcadd((string) $product->pivot->price, '0', 0) : '',
                        ];
                    }
                }
            }
        }

        if ($this->requisitionIds === []) {
            $prefillSupplierId = (int) request()->query('supplier', 0);
            $prefillProductId = (int) request()->query('product', 0);

            if ($prefillSupplierId && $prefillProductId) {
                $supplier = Supplier::find($prefillSupplierId);
                $product = $supplier?->products()->purchasable()->where('products.id', $prefillProductId)->where('products.is_active', true)->first();

                if ($product) {
                    $this->supplierId = (string) $prefillSupplierId;
                    $this->items[] = [
                        'product_id' => (string) $product->id,
                        'quantity_ordered' => '',
                        'unit_price' => $product->pivot->price !== null ? bcadd((string) $product->pivot->price, '0', 0) : '',
                    ];
                }
            }

            if ($this->items === []) {
                $this->addItemRow();
            }
        }
    }

    #[Computed]
    public function sourceRequisitions(): Collection
    {
        return PurchaseRequisition::whereIn('id', $this->requisitionIds)->with(['items.product', 'items.purchaseOrder'])->orderBy('needed_by')->get();
    }

    #[Computed]
    public function pendingRequestedLines(): Collection
    {
        $addedProductIds = collect($this->items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();
        $suppliedProductIds = $this->supplierId ? $this->availableProducts->pluck('id')->all() : [];

        return $this->sourceRequisitions
            ->flatMap(fn (PurchaseRequisition $req) => $req->items
                ->reject(fn ($item) => $item->isFulfilled())
                ->map(fn ($item) => [
                    'product' => $item->product,
                    'quantity' => (string) $item->quantity,
                    'requisition_number' => $req->requisition_number,
                    'needed_by' => $req->needed_by,
                ]))
            ->groupBy(fn (array $row) => $row['product']->id)
            ->map(fn (Collection $rows) => [
                'product' => $rows->first()['product'],
                'quantity' => $rows->reduce(fn (string $carry, array $row) => bcadd($carry, $row['quantity'], 2), '0'),
                'requisition_numbers' => $rows->pluck('requisition_number')->unique()->implode(', '),
                'needed_by' => $rows->pluck('needed_by')->sort()->first(),
                'added' => in_array($rows->first()['product']->id, $addedProductIds, true),
                'suppliedByThisSupplier' => $this->supplierId ? in_array($rows->first()['product']->id, $suppliedProductIds, true) : null,
            ])
            ->sortBy(fn (array $row) => $row['product']->product_name)
            ->values();
    }

    public function addRequestedLine(int $productId): void
    {
        if (! isset($this->requestedLines[$productId])) {
            return;
        }
        if (collect($this->items)->pluck('product_id')->contains((string) $productId)) {
            return;
        }

        $product = $this->availableProducts->firstWhere('id', $productId);
        if (! $product) {
            return;
        }

        $this->items[] = [
            'product_id' => (string) $productId,
            'quantity_ordered' => \App\Support\Decimal::plain($this->requestedLines[$productId]),
            'unit_price' => $product->pivot->price !== null ? bcadd((string) $product->pivot->price, '0', 0) : '',
        ];
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

    public function addChargeRow(): void
    {
        $this->charges[] = ['description' => '', 'amount' => '', 'include_in_cost' => true];
        $this->activeTab = 'charges';
    }

    public function removeChargeRow(int $index): void
    {
        unset($this->charges[$index]);

        $this->charges = array_values($this->charges);
    }

    public function updatedSupplierId(): void
    {
        if ($this->requisitionIds !== []) {
            unset($this->selectedSupplier, $this->availableProducts, $this->pendingRequestedLines);
            $this->items = [];

            return;
        }

        foreach ($this->items as $index => $item) {
            $this->items[$index]['product_id'] = '';
        }
    }

    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::query()
            ->where('is_active', true)
            ->withCount(['products as purchasable_products_count' => fn ($query) => $query->purchasable()->where('products.is_active', true)])
            ->orderBy('supplier_name')
            ->get();
    }

    #[Computed]
    public function supplierOptions(): array
    {
        return $this->suppliers->map(fn (Supplier $supplier) => [
            'id' => $supplier->id,
            'title' => $supplier->supplier_name,
            'subtitle' => $supplier->supplier_code,
            'meta' => trans_choice(':count product|:count products', $supplier->purchasable_products_count),
            'disabled_reason' => $supplier->purchasable_products_count === 0 ? __('No products yet') : null,
        ])->all();
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
        return $this->selectedSupplier?->products()->purchasable()->where('products.is_active', true)->get() ?? collect();
    }

    public function productOptions(int $index): array
    {
        $taken = collect($this->items)->except($index)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();

        return $this->availableProducts->map(fn ($product) => $product->toPickerOption(
            $product->pivot->price !== null
                ? $this->formatRupiah((string) $product->pivot->price).'/'.$product->unit_of_measure
                : null,
            in_array($product->id, $taken, true) ? __('Already chosen') : null,
        ))->all();
    }

    public function rowSubtotal(array $item): string
    {
        $quantity = filled($item['quantity_ordered'] ?? null) ? (string) $item['quantity_ordered'] : '0';
        $price = filled($item['unit_price'] ?? null) ? (string) $item['unit_price'] : '0';

        return bcmul($quantity, $price, 2);
    }

    #[Computed]
    public function itemsSubtotal(): string
    {
        return collect($this->items)->reduce(
            fn (string $carry, array $item) => bcadd($carry, $this->rowSubtotal($item), 2),
            '0'
        );
    }

    #[Computed]
    public function chargesTotal(): string
    {
        return collect($this->charges)->reduce(
            fn (string $carry, array $charge) => bcadd($carry, filled($charge['amount'] ?? null) ? (string) $charge['amount'] : '0', 2),
            '0'
        );
    }

    #[Computed]
    public function estimatedTotal(): string
    {
        return bcadd($this->itemsSubtotal, $this->chargesTotal, 2);
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

    public function formatQuantity(string $quantity): string
    {
        if (! str_contains($quantity, '.')) {
            return $quantity;
        }

        return rtrim(rtrim($quantity, '0'), '.');
    }

    public function isSupplierStepComplete(): bool
    {
        return filled($this->supplierId) && filled($this->orderDate);
    }

    public function isItemsStepComplete(): bool
    {
        return collect($this->items)->contains(
            fn (array $item) => filled($item['product_id'] ?? null)
                && filled($item['quantity_ordered'] ?? null)
                && filled($item['unit_price'] ?? null)
        );
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
            'charges.*.description' => ['required', 'string', 'max:255'],
            'charges.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'supplierId' => __('Supplier'),
            'orderDate' => __('Order Date'),
            'expectedDeliveryDate' => __('Expected Delivery'),
            'items.*.product_id' => __('Product'),
            'items.*.quantity_ordered' => __('Qty'),
            'items.*.unit_price' => __('Unit Price'),
            'charges.*.description' => __('Description'),
            'charges.*.amount' => __('Amount'),
        ];
    }

    public function create(): void
    {
        Gate::authorize('create', PurchaseOrder::class);

        $this->items = array_values(array_filter($this->items, function (array $item): bool {
            return filled($item['product_id']) || filled($item['quantity_ordered']) || filled($item['unit_price']);
        }));
        $this->charges = array_values(array_filter($this->charges, fn (array $row) => filled($row['description']) || filled($row['amount'])));

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            $keys = array_keys($e->errors());
            $chargeErrorsOnly = $keys !== [] && collect($keys)->every(fn ($key) => str_starts_with($key, 'charges.'));
            $this->activeTab = $chargeErrorsOnly ? 'charges' : 'items';
            throw $e;
        }

        $data = new CreatePurchaseOrderData(
            supplierId: $this->supplierId,
            orderDate: $this->orderDate,
            expectedDeliveryDate: $this->expectedDeliveryDate,
            createdBy: Auth::id(),
            items: $this->items,
            charges: collect($this->charges)->map(fn (array $row) => [
                'description' => (string) $row['description'],
                'amount' => (string) $row['amount'],
                'include_in_cost' => (bool) ($row['include_in_cost'] ?? true),
            ])->all(),
            requisitionIds: $this->requisitionIds,
        );

        try {
            $purchaseOrder = app(CreatePurchaseOrderAction::class)->handle($data);
        } catch (NonPurchasableProductException $e) {
            $this->addError('items', $e->userMessage());

            return;
        } catch (InvalidDeliveryDateException $e) {
            Flux::toast(text: $e->userMessage());

            return;
        } catch (InvalidPurchaseRequisitionException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Purchase order created.'));

        $this->redirect(route('purchasing.orders.show', $purchaseOrder), navigate: true);
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

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('purchasing.dashboard')" wire:navigate>{{ __('Purchasing') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('purchasing.orders.list')" wire:navigate>{{ __('Purchase Orders') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Create') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ __('New Purchase Order') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                {{ __('Opened') }} {{ now()->format('d M Y') }}
            </p>
        </div>

        <flux:button variant="primary" wire:click="create" wire:loading.attr="disabled" wire:target="create">
            {{ __('Save Purchase Order') }}
        </flux:button>
    </div>

    @if ($requisitionIds !== [])
        <div class="mt-5 rounded-lg border border-accent/30 bg-accent/5 px-4 py-3 text-xs text-zinc-700 dark:text-zinc-300">
            <p class="font-medium text-zinc-900 dark:text-white">{{ __('From purchase requests') }}</p>
            <p class="mt-1 text-zinc-500 dark:text-zinc-400">{{ $supplierId ? __('Add each material you want on this PO. Materials this supplier doesn\'t sell stay on the request, open for another supplier.') : __('Choose a supplier, then add the requested materials it sells one by one.') }}</p>
            <ul class="mt-2 divide-y divide-accent/10">
                @foreach ($this->pendingRequestedLines as $line)
                    <li wire:key="pending-line-{{ $line['product']->id }}" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2">
                        <span class="min-w-0">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $line['product']->product_name }}</span>
                            <span class="font-data text-zinc-500 dark:text-zinc-400"> · {{ \App\Support\Decimal::plain($line['quantity']) }} {{ $line['product']->unit_of_measure }}</span>
                            <span class="block text-[11px] text-zinc-500 dark:text-zinc-400">{{ $line['requisition_numbers'] }} · {{ __('needed by :date', ['date' => $line['needed_by']->translatedFormat('d M Y')]) }}</span>
                        </span>
                        @if ($line['added'])
                            <span class="inline-flex shrink-0 items-center gap-1 font-medium text-green-700 dark:text-green-400">
                                <flux:icon.check variant="micro" class="size-3.5" />{{ __('Added') }}
                            </span>
                        @elseif (! $supplierId)
                            <span class="shrink-0 text-zinc-400 dark:text-zinc-500">{{ __('Choose a supplier first') }}</span>
                        @elseif ($line['suppliedByThisSupplier'])
                            <flux:button size="sm" variant="filled" wire:click="addRequestedLine({{ $line['product']->id }})" wire:loading.attr="disabled" wire:target="addRequestedLine({{ $line['product']->id }})" class="shrink-0 active:scale-[0.95]">{{ __('Add') }}</flux:button>
                        @else
                            <span class="inline-flex shrink-0 items-center gap-1 text-amber-700 dark:text-amber-400">
                                <flux:icon.exclamation-triangle variant="micro" class="size-3.5" />{{ __('Not sold by this supplier') }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-6 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 shadow-md shadow-zinc-900/4 dark:shadow-none p-6 sm:p-8 lg:p-10 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-x-10 gap-y-10">
            <div class="lg:col-span-2 space-y-10">
                <section class="motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
                    <div class="flex items-center gap-2.5 pb-2.5 mb-5 border-b border-zinc-200 dark:border-white/10">
                        <span class="flex items-center justify-center size-5">
                            @if ($this->isSupplierStepComplete())
                                <span wire:key="step-1-done" wire:transition class="flex items-center justify-center size-5 rounded-sm bg-green-500/15 text-green-600 dark:text-green-400">
                                    <flux:icon.check class="size-3" />
                                </span>
                            @else
                                <span wire:key="step-1-pending" wire:transition class="flex items-center justify-center size-5 rounded-sm bg-accent/10 dark:bg-accent/20 font-data text-[10px] font-bold text-accent">1</span>
                            @endif
                        </span>
                        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">
                            {{ __('Supplier & Schedule') }}
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-5 items-start gap-5">
                        <div class="md:col-span-3">
                            @if ($this->selectedSupplier)
                                <div wire:key="supplier-card" wire:transition class="rounded-md border border-zinc-200 dark:border-white/10 border-s-[3px] border-s-accent bg-zinc-50 dark:bg-white/[0.03] px-4 py-3.5">
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
                                <div wire:key="supplier-select" wire:transition>
                                    <flux:field>
                                        <flux:label>{{ __('Supplier') }}</flux:label>
                                        <x-picker
                                            model="supplierId"
                                            size="md"
                                            :options="$this->supplierOptions"
                                            :selected="(string) $supplierId"
                                            :placeholder="__('Select a registered supplier...')"
                                            :label="__('Supplier')"
                                            :invalid="$errors->has('supplierId')"
                                        />
                                        @error('supplierId') <flux:error class="mt-1" :message="$message" /> @enderror
                                    </flux:field>

                                    <div wire:loading wire:target="supplierId" class="flex items-center gap-1.5 mt-1.5 text-xs text-accent">
                                        <flux:icon.loading variant="micro" class="size-3.5" />
                                        {{ __('Loading supplier details...') }}
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="md:col-span-2 grid grid-cols-1 gap-4">
                            <flux:input type="date" wire:model="orderDate" :label="__('Order Date')" disabled />
                            <flux:input type="date" wire:model="expectedDeliveryDate" :label="__('Expected Delivery')" :min="$orderDate" :error:icon="false" />
                        </div>
                    </div>
                </section>

                <section class="motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;" x-data="{ tab: $wire.entangle('activeTab') }">
                    @php($chargeErrors = collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'charges')))
                    <div class="flex items-end justify-between gap-3 pb-2.5 mb-5 border-b border-zinc-200 dark:border-white/10">
                        <div class="flex items-center gap-2.5">
                            <span class="flex items-center justify-center size-5">
                                @if ($this->isItemsStepComplete())
                                    <span wire:key="step-2-done" wire:transition class="flex items-center justify-center size-5 rounded-sm bg-green-500/15 text-green-600 dark:text-green-400">
                                        <flux:icon.check class="size-3" />
                                    </span>
                                @else
                                    <span wire:key="step-2-pending" wire:transition class="flex items-center justify-center size-5 rounded-sm bg-accent/10 dark:bg-accent/20 font-data text-[10px] font-bold text-accent">2</span>
                                @endif
                            </span>
                            <x-tab-bar :label="__('Order lines')" :tabs="[
                                'items' => ['label' => __('Line Items'), 'count' => count($items)],
                                'charges' => ['label' => __('Other charges'), 'count' => count($charges), 'error' => $chargeErrors],
                            ]" />
                        </div>

                        <flux:button size="sm" variant="ghost" icon="plus" x-show="tab === 'items'" wire:click="addItemRow" :disabled="!$supplierId" class="active:scale-[0.95]">
                            {{ __('Add Row') }}
                        </flux:button>
                        <flux:button size="sm" variant="ghost" icon="plus" x-show="tab === 'charges'" x-cloak wire:click="addChargeRow" class="active:scale-[0.95]">
                            {{ __('Add charge') }}
                        </flux:button>
                    </div>

                    <div role="tabpanel" x-show="tab === 'items'">

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
                        <div class="@container hidden lg:block rounded-md border border-zinc-200 dark:border-white/10" role="table" aria-label="{{ __('Line Items') }}">
                            <div role="row" class="hidden @3xl:grid grid-cols-[minmax(0,1fr)_8.5rem_8.5rem_8rem_2rem] gap-x-3 px-3 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10 rounded-t-md">
                                <span role="columnheader">{{ __('Product') }}</span>
                                <span role="columnheader" class="text-right">{{ __('Qty') }}</span>
                                <span role="columnheader" class="text-right">{{ __('Unit Price') }}</span>
                                <span role="columnheader" class="text-right">{{ __('Subtotal') }}</span>
                                <span role="columnheader"><span class="sr-only">{{ __('Remove') }}</span></span>
                            </div>

                            @foreach ($items as $index => $item)
                                <?php $product = $this->availableProducts->firstWhere('id', (int) ($item['product_id'] ?? 0)); ?>
                                <div
                                    role="row"
                                    wire:key="item-row-{{ $index }}"
                                    wire:transition
                                    x-data="{ focused: false }"
                                    x-on:focusin="focused = true"
                                    x-on:focusout="focused = false"
                                    :class="focused && 'bg-accent/5 dark:bg-accent/10'"
                                    class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_2rem] @3xl:grid-cols-[minmax(0,1fr)_8.5rem_8.5rem_8rem_2rem] gap-x-3 gap-y-2.5 @3xl:gap-y-0 items-start px-3 py-3 @3xl:py-2.5 border-b border-zinc-100 dark:border-white/5 last:border-0 transition-colors"
                                >
                                    <div role="cell" class="col-span-3 @3xl:col-span-1 min-w-0">
                                        <x-picker :model="'items.'.$index.'.product_id'" :options="$this->productOptions($index)" :selected="(string) $item['product_id']" :placeholder="__('Select product...')" :label="__('Product')" :invalid="$errors->has('items.'.$index.'.product_id')" />
                                        @error("items.$index.product_id") <flux:error class="mt-1" :message="$message" /> @enderror
                                    </div>

                                    <div role="cell" class="min-w-0">
                                        <p class="@3xl:hidden mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Qty') }}</p>
                                        @if ($product)
                                            <flux:input.group>
                                                <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-data tabular-nums" wire:model.live="items.{{ $index }}.quantity_ordered" :loading="false" :aria-label="__('Qty')" />
                                                <flux:input.group.suffix class="gap-1 px-2 text-xs" title="{{ $product->isBelowReorderPoint() ? __('Low stock') : __('Stock available') }}: {{ $product->current_stock }}">
                                                    {{ $product->unit_of_measure }}
                                                    <span class="size-1.5 rounded-full {{ $product->isBelowReorderPoint() ? 'bg-red-500' : 'bg-green-500' }}" aria-hidden="true"></span>
                                                    <span class="sr-only">{{ $product->isBelowReorderPoint() ? __('Low stock') : __('Stock available') }}</span>
                                                </flux:input.group.suffix>
                                            </flux:input.group>
                                        @else
                                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-data tabular-nums" wire:model.live="items.{{ $index }}.quantity_ordered" :loading="false" :aria-label="__('Qty')" />
                                        @endif
                                        @error("items.$index.quantity_ordered") <flux:error class="mt-1" :message="$message" /> @enderror
                                    </div>

                                    <div role="cell" class="min-w-0">
                                        <p class="@3xl:hidden mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Unit Price') }}</p>
                                        <flux:input.group>
                                            <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-data tabular-nums" wire:model.live="items.{{ $index }}.unit_price" :loading="false" :aria-label="__('Unit Price')" />
                                        </flux:input.group>
                                        @error("items.$index.unit_price") <flux:error class="mt-1" :message="$message" /> @enderror
                                    </div>

                                    <div role="cell" class="min-w-0 text-right">
                                        <p class="@3xl:hidden mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Subtotal') }}</p>
                                        <div class="flex min-h-8 items-center justify-end whitespace-nowrap">
                                            <span wire:loading.remove wire:target="items.{{ $index }}.quantity_ordered,items.{{ $index }}.unit_price"
                                                  class="font-data text-xs font-medium tabular-nums text-zinc-900 dark:text-white">
                                                {{ $this->formatRupiah($this->rowSubtotal($item)) }}
                                            </span>
                                            <flux:icon.loading wire:loading wire:target="items.{{ $index }}.quantity_ordered,items.{{ $index }}.unit_price" variant="micro" class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                                        </div>
                                    </div>

                                    <div role="cell" class="col-start-4 row-start-1 @3xl:col-start-5 flex justify-end">
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeItemRow({{ $index }})" :aria-label="__('Remove')" class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 active:scale-[0.95]" />
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="lg:hidden space-y-3">
                            @foreach ($items as $index => $item)
                                <?php $product = $this->availableProducts->firstWhere('id', (int) ($item['product_id'] ?? 0)); ?>
                                <div wire:key="item-card-{{ $index }}" wire:transition class="rounded-md border border-zinc-200 dark:border-white/10 p-3.5">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex-1">
                                            <x-picker :model="'items.'.$index.'.product_id'" :options="$this->productOptions($index)" :selected="(string) $item['product_id']" :placeholder="__('Select product...')" :label="__('Product')" :invalid="$errors->has('items.'.$index.'.product_id')" />
                                            @error("items.$index.product_id") <flux:error class="mt-1" :message="$message" /> @enderror
                                        </div>
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeItemRow({{ $index }})" class="active:scale-[0.95] shrink-0" />
                                    </div>

                                    @if ($product)
                                        @if ($product->isBelowReorderPoint())
                                            <span class="inline-flex items-center mt-2 gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-1.5 py-0.5 font-data tabular-nums text-[11px] text-red-700 dark:text-red-400">
                                                <flux:icon.exclamation-triangle variant="micro" class="size-3" />
                                                {{ __('Low') }} {{ $product->current_stock }} {{ $product->unit_of_measure }}
                                            </span>
                                        @else
                                            <span class="mt-2 block font-data tabular-nums text-xs text-zinc-500 dark:text-zinc-400">{{ __('Stock') }} {{ $product->current_stock }} {{ $product->unit_of_measure }}</span>
                                        @endif
                                    @endif

                                    <div class="grid grid-cols-2 items-start gap-3 mt-3">
                                        <div>
                                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" :label="__('Qty')" input:class="text-right font-data tabular-nums" wire:model.live="items.{{ $index }}.quantity_ordered" :loading="false" />
                                            @error("items.$index.quantity_ordered") <flux:error class="mt-1" :message="$message" /> @enderror
                                        </div>
                                        <div>
                                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" :label="__('Unit Price')" input:class="text-right font-data tabular-nums" wire:model.live="items.{{ $index }}.unit_price" :loading="false" />
                                            @error("items.$index.unit_price") <flux:error class="mt-1" :message="$message" /> @enderror
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between gap-2 mt-3 pt-3 border-t border-zinc-100 dark:border-white/10">
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Subtotal') }}</span>
                                        <span
                                            wire:loading.remove
                                            wire:target="items.{{ $index }}.quantity_ordered,items.{{ $index }}.unit_price"
                                            class="font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white"
                                        >
                                            {{ $this->formatRupiah($this->rowSubtotal($item)) }}
                                        </span>
                                        <span
                                            wire:loading
                                            wire:target="items.{{ $index }}.quantity_ordered,items.{{ $index }}.unit_price"
                                        >
                                            <flux:icon.loading variant="micro" class="size-3.5 text-zinc-400 dark:text-zinc-500" />
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @error('items') <flux:error class="mt-3" :message="$message" /> @enderror
                    @endif
                    </div>
                    </div>

                    <div role="tabpanel" x-show="tab === 'charges'" x-cloak>
                        @if ($charges === [])
                            <div class="flex flex-col items-center gap-3 rounded-md border border-dashed border-zinc-200 dark:border-white/10 py-10 text-center">
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No other charges — add one for e.g. shipping or packing.') }}</p>
                                <flux:button size="sm" variant="filled" icon="plus" wire:click="addChargeRow" class="active:scale-[0.95]">{{ __('Add charge') }}</flux:button>
                            </div>
                        @else
                            <div class="rounded-md border border-zinc-200 dark:border-white/10">
                                <div class="hidden sm:grid grid-cols-[minmax(0,1fr)_11rem_2rem] gap-x-3 px-3 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10 rounded-t-md">
                                    <span>{{ __('Description') }}</span>
                                    <span class="text-right">{{ __('Amount') }}</span>
                                    <span></span>
                                </div>
                                @foreach ($charges as $index => $charge)
                                    <div wire:key="charge-row-{{ $index }}" wire:transition class="grid grid-cols-[minmax(0,1fr)_2rem] sm:grid-cols-[minmax(0,1fr)_11rem_2rem] gap-x-3 gap-y-2 items-start px-3 py-3 border-b border-zinc-100 dark:border-white/5 last:border-0">
                                        <div class="min-w-0">
                                            <flux:input size="sm" wire:model="charges.{{ $index }}.description" :placeholder="__('e.g. Shipping, Packing')" :aria-label="__('Description')" />
                                            @error("charges.$index.description") <flux:error class="mt-1" :message="$message" /> @enderror
                                            <div class="mt-2"><flux:checkbox wire:model="charges.{{ $index }}.include_in_cost" :label="__('Include in inventory cost')" /></div>
                                        </div>
                                        <div class="col-span-2 row-start-2 sm:col-span-1 sm:row-start-1 sm:col-start-2">
                                            <flux:input.group>
                                                <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                                                <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" input:class="text-right font-data tabular-nums" wire:model.live.debounce.400ms="charges.{{ $index }}.amount" :loading="false" :aria-label="__('Amount')" />
                                            </flux:input.group>
                                            @error("charges.$index.amount") <flux:error class="mt-1" :message="$message" /> @enderror
                                        </div>
                                        <div class="col-start-2 row-start-1 sm:col-start-3 flex justify-end">
                                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeChargeRow({{ $index }})" :aria-label="__('Remove')" class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 active:scale-[0.95]" />
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @error('charges') <flux:error class="mt-3" :message="$message" /> @enderror
                    </div>
                </section>
            </div>

            <div class="lg:col-span-1">
                <div class="lg:sticky lg:top-6 motion-safe:animate-fade-slide-up" style="animation-delay: 130ms;">
                    <div class="rounded-md border border-zinc-200 dark:border-white/10 overflow-hidden">
                        <div class="px-4 py-3 border-b border-zinc-200 dark:border-white/10">
                            <h2 class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Order Summary') }}</h2>
                        </div>

                        <dl class="divide-y divide-zinc-100 dark:divide-white/5 text-sm">
                            <div class="flex items-center justify-between px-4 py-2.5">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Line Items') }}</dt>
                                <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ str_pad((string) count($items), 2, '0', STR_PAD_LEFT) }}</dd>
                            </div>
                            @if (bccomp($this->chargesTotal, '0', 2) > 0)
                                <div class="flex items-center justify-between px-4 py-2.5">
                                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Other charges') }}</dt>
                                    <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatRupiah($this->chargesTotal) }}</dd>
                                </div>
                            @endif
                            <div class="flex items-center justify-between px-4 py-2.5">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Total Qty') }}</dt>
                                <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatQuantity($this->totalQuantity) }}</dd>
                            </div>
                        </dl>

                        <div class="px-4 py-4 bg-accent">
                            <p class="text-[10px] font-semibold uppercase tracking-widest text-accent-foreground/70">{{ __('Est. Total') }}</p>
                            <p wire:key="est-total-{{ $this->estimatedTotal }}" wire:transition.duration.300ms class="font-data text-xl font-medium tabular-nums text-accent-foreground mt-0.5">{{ $this->formatRupiah($this->estimatedTotal) }}</p>
                            <x-tax-estimate :amount="$this->estimatedTotal" />
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
