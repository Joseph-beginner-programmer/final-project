<?php

use App\Actions\Sales\SaveSalesOrderDraftAction;
use App\DTO\Sales\SalesOrderData;
use App\Enums\PaymentTerm;
use App\Enums\ProductType;
use App\Exceptions\InvalidSalesOrderException;
use App\Exceptions\SalesOrderNotEditableException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Support\IncomingStock;
use App\Support\ProductCost;
use App\Support\SalesAvailability;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales Order')] class extends Component {
    #[Locked]
    public ?int $salesOrderId = null;

    #[Locked]
    public string $soNumber = '';

    public string $customerId = '';

    public ?int $sourcesProductId = null;

    #[Locked]
    public string $orderDate = '';

    public ?string $expectedDeliveryDate = null;

    public string $deliveryAddress = '';

    public string $contactPerson = '';

    public string $contactPhone = '';

    public string $paymentTerms = 'cash';

    public string $discountPercent = '';

    public string $notes = '';

    public array $items = [];

    public array $charges = [];

    public string $activeTab = 'products';

    public function mount(?SalesOrder $salesOrder = null): void
    {
        if ($salesOrder?->exists) {
            Gate::authorize('update', $salesOrder);

            $this->salesOrderId = $salesOrder->id;
            $this->soNumber = $salesOrder->so_number;
            $this->customerId = (string) $salesOrder->customer_id;
            $this->orderDate = $salesOrder->order_date->toDateString();
            $this->expectedDeliveryDate = $salesOrder->expected_delivery_date?->toDateString();
            $this->deliveryAddress = (string) $salesOrder->delivery_address;
            $this->contactPerson = (string) $salesOrder->contact_person;
            $this->contactPhone = (string) $salesOrder->contact_phone;
            $this->paymentTerms = $salesOrder->payment_terms->value;
            $this->discountPercent = bccomp((string) $salesOrder->discount_percent, '0', 2) > 0 ? $this->formatQuantity((string) $salesOrder->discount_percent) : '';
            $this->notes = (string) $salesOrder->notes;
            $this->items = $salesOrder->items->map(fn ($item) => [
                'product_id' => (string) $item->product_id,
                'quantity' => $this->formatQuantity((string) $item->quantity_ordered),
                'unit_price' => bcadd((string) $item->unit_price, '0', 0),
            ])->all();
            $this->charges = $salesOrder->charges->map(fn ($charge) => [
                'description' => $charge->description,
                'amount' => bcadd((string) $charge->amount, '0', 0),
            ])->all();

            return;
        }

        Gate::authorize('create', SalesOrder::class);

        $this->orderDate = today()->toDateString();
        $this->addItemRow();
    }

    public function addItemRow(): void
    {
        $this->items[] = ['product_id' => '', 'quantity' => '', 'unit_price' => ''];
    }

    public function removeItemRow(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function addChargeRow(): void
    {
        $this->charges[] = ['description' => '', 'amount' => ''];
        $this->activeTab = 'charges';
    }

    public function removeChargeRow(int $index): void
    {
        unset($this->charges[$index]);
        $this->charges = array_values($this->charges);
    }

    public function updatedCustomerId(mixed $value): void
    {
        $customer = Customer::find($value);
        $this->deliveryAddress = (string) $customer?->address;
        $this->contactPerson = (string) $customer?->contact_person;
        $this->contactPhone = (string) $customer?->phone;
    }

    public function updated(string $name, mixed $value): void
    {
        if (preg_match('/^items\.(\d+)\.product_id$/', $name, $matches)) {
            $price = $this->products->firstWhere('id', (int) $value)?->selling_price;
            $this->items[(int) $matches[1]]['unit_price'] = $price !== null ? bcadd((string) $price, '0', 0) : '';
        }
    }

    #[Computed]
    public function customers(): Collection
    {
        return Customer::orderBy('customer_name')->get();
    }

    #[Computed]
    public function customerOptions(): array
    {
        return $this->customers->map(fn (Customer $customer) => [
            'id' => $customer->id,
            'title' => $customer->customer_name,
            'subtitle' => $customer->customer_code,
            'meta' => $customer->contact_person,
            'disabled_reason' => null,
        ])->all();
    }

    #[Computed]
    public function selectedCustomer(): ?Customer
    {
        return $this->customerId ? $this->customers->firstWhere('id', (int) $this->customerId) : null;
    }

    #[Computed]
    public function paymentTermOptions(): array
    {
        return collect(PaymentTerm::cases())->map(fn (PaymentTerm $term) => [
            'id' => $term->value,
            'title' => $term->label(),
            'subtitle' => null,
            'meta' => null,
            'disabled_reason' => null,
        ])->all();
    }

    #[Computed]
    public function products(): Collection
    {
        $onOrder = collect($this->items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();

        return Product::where('type', ProductType::FinishedGoods->value)
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $onOrder))
            ->orderBy('product_name')
            ->get();
    }

    #[Computed]
    public function incoming(): array
    {
        return IncomingStock::forProducts($this->products->pluck('id'));
    }

    #[Computed]
    public function reserved(): array
    {
        return SalesAvailability::reserved($this->products->pluck('id'), $this->salesOrderId);
    }

    #[Computed]
    public function sourcesProduct(): ?Product
    {
        return $this->sourcesProductId ? $this->products->firstWhere('id', $this->sourcesProductId) : null;
    }

    #[Computed]
    public function reservedOrders(): array
    {
        return $this->sourcesProductId ? SalesAvailability::reservedOrders([$this->sourcesProductId], $this->salesOrderId)[$this->sourcesProductId] ?? [] : [];
    }

    public function costPreview(int $productId, mixed $quantity): array
    {
        $quantity = is_numeric($quantity) && (float) $quantity > 0 ? (string) $quantity : '1';

        return ProductCost::fifoPreview($productId, $quantity, $this->reserved[$productId] ?? '0');
    }

    public function rowQuantity(int $productId): string
    {
        $row = collect($this->items)->first(fn (array $item) => (int) ($item['product_id'] ?? 0) === $productId);

        return is_numeric($row['quantity'] ?? null) ? (string) $row['quantity'] : '';
    }

    public function rowPrice(int $productId): ?string
    {
        $row = collect($this->items)->first(fn (array $item) => (int) ($item['product_id'] ?? 0) === $productId);

        return is_numeric($row['unit_price'] ?? null) ? (string) $row['unit_price'] : null;
    }

    public function showSources(int $productId): void
    {
        $this->sourcesProductId = $productId;
        unset($this->sourcesProduct, $this->reservedOrders);
        $this->modal('stock-sources')->show();
    }

    public function productOptions(int $index): array
    {
        $taken = collect($this->items)->except($index)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();

        return $this->products->map(fn (Product $product) => $product->toPickerOption(
            __('Stock').' '.$this->formatQuantity((string) $product->current_stock).' '.$product->unit_of_measure,
            in_array($product->id, $taken, true) ? __('Already chosen') : null,
        ))->all();
    }

    public function rowSubtotal(array $item): string
    {
        $quantity = is_numeric($item['quantity'] ?? null) ? (string) $item['quantity'] : '0';
        $price = is_numeric($item['unit_price'] ?? null) ? (string) $item['unit_price'] : '0';

        return bcmul($quantity, $price, 2);
    }

    #[Computed]
    public function itemsSubtotal(): string
    {
        return collect($this->items)->reduce(fn (string $carry, array $item) => bcadd($carry, $this->rowSubtotal($item), 2), '0');
    }

    #[Computed]
    public function chargesTotal(): string
    {
        return collect($this->charges)->reduce(fn (string $carry, array $charge) => bcadd($carry, is_numeric($charge['amount'] ?? null) ? (string) $charge['amount'] : '0', 2), '0');
    }

    #[Computed]
    public function discountPercentValue(): string
    {
        return is_numeric($this->discountPercent) ? (string) $this->discountPercent : '0';
    }

    #[Computed]
    public function discount(): string
    {
        return SaveSalesOrderDraftAction::discountAmount($this->itemsSubtotal, $this->discountPercentValue);
    }

    #[Computed]
    public function total(): string
    {
        return bcadd(bcsub($this->itemsSubtotal, $this->discount, 2), $this->chargesTotal, 2);
    }

    public function formatQuantity(string $quantity): string
    {
        if ($quantity === '' || ! str_contains($quantity, '.')) {
            return $quantity;
        }

        return rtrim(rtrim($quantity, '0'), '.');
    }

    public function formatRupiah(string $amount): string
    {
        $sign = bccomp($amount, '0', 2) < 0 ? '−' : '';

        return $sign.'Rp '.number_format(abs((float) $amount), 0, ',', '.');
    }

    protected function rules(): array
    {
        return [
            'customerId' => ['required', 'exists:customers,id'],
            'expectedDeliveryDate' => ['nullable', 'date', 'after_or_equal:orderDate'],
            'paymentTerms' => ['required', Rule::enum(PaymentTerm::class)],
            'discountPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'deliveryAddress' => ['nullable', 'string', 'max:1000'],
            'contactPerson' => ['nullable', 'string', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0'],
            'charges.*.description' => ['required', 'string', 'max:255'],
            'charges.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }

    protected function messages(): array
    {
        return [
            'items.*.product_id.distinct' => __('This product is already on the order.'),
            'expectedDeliveryDate.after_or_equal' => __('The expected delivery date cannot be before the order date.'),
            'discountPercent.max' => __('The discount must be between 0 and 100%.'),
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'customerId' => __('Customer'),
            'expectedDeliveryDate' => __('Delivery Date'),
            'paymentTerms' => __('Payment Terms'),
            'discountPercent' => __('Discount'),
            'deliveryAddress' => __('Delivery Address'),
            'contactPerson' => __('Contact person'),
            'contactPhone' => __('Contact Number'),
            'notes' => __('Notes'),
            'items.*.product_id' => __('Product'),
            'items.*.quantity' => __('Qty'),
            'items.*.unit_price' => __('Selling Price'),
            'charges.*.description' => __('Description'),
            'charges.*.amount' => __('Amount'),
        ];
    }

    public function save(): void
    {
        $order = $this->salesOrderId ? SalesOrder::findOrFail($this->salesOrderId) : null;
        $order ? Gate::authorize('update', $order) : Gate::authorize('create', SalesOrder::class);

        $this->items = array_values(array_filter($this->items, fn (array $row) => filled($row['product_id']) || filled($row['quantity'])));
        $this->charges = array_values(array_filter($this->charges, fn (array $row) => filled($row['description']) || filled($row['amount'])));

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($this->items === []) {
                $this->addItemRow();
            }
            $keys = array_keys($e->errors());
            $chargeErrorsOnly = $keys !== [] && collect($keys)->every(fn ($key) => str_starts_with($key, 'charges.'));
            $this->activeTab = $chargeErrorsOnly ? 'charges' : (collect($keys)->contains(fn ($key) => str_starts_with($key, 'items')) ? 'products' : $this->activeTab);
            Flux::toast(variant: 'danger', text: __('Please fix the highlighted fields before submitting.'));
            throw $e;
        }

        $data = new SalesOrderData(
            customerId: (int) $this->customerId,
            salespersonId: $order?->salesperson_id ?? Auth::id(),
            orderDate: $this->orderDate,
            expectedDeliveryDate: $this->expectedDeliveryDate,
            paymentTerms: PaymentTerm::from($this->paymentTerms),
            lines: collect($this->items)->map(fn (array $row) => [
                'productId' => (int) $row['product_id'],
                'quantity' => (string) $row['quantity'],
                'unitPrice' => (string) $row['unit_price'],
            ])->all(),
            discountPercent: $this->discountPercentValue,
            charges: collect($this->charges)->map(fn (array $row) => [
                'description' => (string) $row['description'],
                'amount' => (string) $row['amount'],
            ])->all(),
            notes: $this->notes,
            deliveryAddress: $this->deliveryAddress,
            contactPerson: $this->contactPerson,
            contactPhone: $this->contactPhone,
        );

        try {
            $order = app(SaveSalesOrderDraftAction::class)->handle($data, Auth::id(), $order);
        } catch (InvalidSalesOrderException|SalesOrderNotEditableException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Draft :number saved. Confirm it so the warehouse can deliver.', ['number' => $order->so_number]));
        $this->redirectRoute('sales.orders.show', $order, navigate: true);
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
        <flux:breadcrumbs.item :href="route('sales.dashboard')" wire:navigate>{{ __('Sales') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('sales.orders.list')" wire:navigate>{{ __('Sales Orders') }}</flux:breadcrumbs.item>
        @if ($salesOrderId)
            <flux:breadcrumbs.item :href="route('sales.orders.show', $salesOrderId)" wire:navigate>{{ $soNumber }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Edit') }}</flux:breadcrumbs.item>
        @else
            <flux:breadcrumbs.item>{{ __('Create') }}</flux:breadcrumbs.item>
        @endif
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ $salesOrderId ? __('Edit Sales Order') : __('New Sales Order') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            @if ($salesOrderId)
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5"><span class="font-data text-accent">{{ $soNumber }}</span></p>
            @endif
        </div>

        <flux:button variant="primary" icon="document-check" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">
            {{ __('Save Draft') }}
        </flux:button>
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4 items-start motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="lg:col-span-2 rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-5">
            <div class="grid grid-cols-1 md:grid-cols-2 items-start gap-4">
                <div class="md:col-span-2">
                    <flux:field>
                        <flux:label>{{ __('Customer') }}</flux:label>
                        <x-picker model="customerId" size="md" :options="$this->customerOptions" :selected="(string) $customerId"
                            :placeholder="__('Select a customer...')" :label="__('Customer')" :invalid="$errors->has('customerId')" />
                        @error('customerId') <flux:error class="mt-1" :message="$message" /> @enderror
                    </flux:field>
                    @if ($this->customers->isEmpty())
                        <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('No customers yet.') }} <a href="{{ route('sales.customers.list') }}" wire:navigate class="text-accent hover:underline">{{ __('Add one first') }}</a>
                        </p>
                    @endif
                </div>

                @php($noCustomer = blank($customerId))
                <flux:input wire:model="contactPerson" :label="__('Contact person')" :disabled="$noCustomer" />
                <flux:input wire:model="contactPhone" :label="__('Contact Number')" type="tel" :disabled="$noCustomer" />
                <div class="md:col-span-2">
                    <flux:textarea wire:model="deliveryAddress" :label="__('Delivery Address')" rows="2" :disabled="$noCustomer"
                        :placeholder="$noCustomer ? __('Choose a customer first') : __('Filled from the customer — change it for this order if needed')" />
                    <div wire:loading wire:target="customerId" class="mt-1 flex items-center gap-1.5 text-xs text-accent">
                        <flux:icon.loading variant="micro" class="size-3.5" />
                        {{ __('Loading customer details...') }}
                    </div>
                </div>

                <flux:input type="date" :value="$orderDate" :label="__('Order Date')" disabled />
                <flux:input type="date" wire:model="expectedDeliveryDate" :label="__('Delivery Date')" :min="$orderDate" />

                <flux:field class="md:col-span-2">
                    <flux:label>{{ __('Payment Terms') }}</flux:label>
                    <x-picker model="paymentTerms" size="md" :options="$this->paymentTermOptions" :selected="$paymentTerms"
                        :placeholder="__('Select payment terms...')" :label="__('Payment Terms')" :invalid="$errors->has('paymentTerms')" />
                    @error('paymentTerms') <flux:error class="mt-1" :message="$message" /> @enderror
                </flux:field>
            </div>

            <div class="mt-4">
                <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
            </div>
        </div>

        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 overflow-hidden flex flex-col">
            <div class="p-5 flex-1">
                <p class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Order Value') }}</p>
                <dl class="mt-3 space-y-2.5 text-xs">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Products subtotal') }}</dt>
                        <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatRupiah($this->itemsSubtotal) }}</dd>
                    </div>
                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Discount') }} <span class="text-zinc-400">({{ __('optional') }})</span></dt>
                            <dd class="w-28">
                                <flux:input.group>
                                    <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false"
                                        wire:model.live.debounce.400ms="discountPercent" :aria-label="__('Discount')" />
                                    <flux:input.group.suffix class="px-2 text-xs">%</flux:input.group.suffix>
                                </flux:input.group>
                            </dd>
                        </div>
                        @if (bccomp($this->discount, '0', 2) > 0)
                            <p class="mt-1 text-right font-data text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400">−{{ $this->formatRupiah($this->discount) }}</p>
                        @endif
                        @error('discountPercent') <flux:error class="mt-1 text-right" :message="$message" /> @enderror
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Other charges') }}</dt>
                        <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatRupiah($this->chargesTotal) }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Prices exclude tax — tax goes on the invoice.') }}</p>
            </div>
            <div class="px-5 py-4 bg-accent">
                <p class="text-[10px] font-semibold uppercase tracking-widest text-accent-foreground/70">{{ __('Total') }}</p>
                <p class="font-data text-xl font-medium tabular-nums text-accent-foreground mt-0.5">{{ $this->formatRupiah($this->total) }}</p>
                <x-tax-estimate :amount="$this->total" />
            </div>
        </div>
    </div>

    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;" x-data="{ tab: $wire.entangle('activeTab') }">
        @php($itemErrors = collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'items')))
        @php($chargeErrors = collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'charges')))
        <div class="flex items-end justify-between gap-3">
            <x-tab-bar class="flex-1" :label="__('Order lines')" :tabs="[
                'products' => ['label' => __('Products'), 'count' => count($items), 'error' => $itemErrors],
                'charges' => ['label' => __('Other charges'), 'count' => count($charges), 'error' => $chargeErrors],
            ]" />
            <div class="pb-1.5">
                <flux:button size="sm" variant="ghost" icon="plus" x-show="tab === 'products'" wire:click="addItemRow" class="active:scale-[0.95]">{{ __('Add Row') }}</flux:button>
                <flux:button size="sm" variant="ghost" icon="plus" x-show="tab === 'charges'" x-cloak wire:click="addChargeRow" class="active:scale-[0.95]">{{ __('Add charge') }}</flux:button>
            </div>
        </div>

        <div role="tabpanel" x-show="tab === 'products'" class="mt-4">
            <div class="@container rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
                <div class="hidden @3xl:grid grid-cols-[minmax(0,1fr)_7rem_9rem_8.5rem_2rem] gap-x-3 px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10 rounded-t-xl">
                    <span>{{ __('Product') }}</span>
                    <span class="text-right">{{ __('Qty') }}</span>
                    <span class="text-right">{{ __('Selling Price') }}</span>
                    <span class="text-right">{{ __('Subtotal') }}</span>
                    <span></span>
                </div>

                @foreach ($items as $index => $item)
                    @php($product = $this->products->firstWhere('id', (int) ($item['product_id'] ?? 0)))
                    <div wire:key="so-row-{{ $index }}" class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_2rem] @3xl:grid-cols-[minmax(0,1fr)_7rem_9rem_8.5rem_2rem] gap-x-3 gap-y-2 items-start px-4 py-3 border-b border-zinc-100 dark:border-white/5 last:border-0">
                        <div class="col-span-2 @3xl:col-span-1 min-w-0">
                            <x-picker :model="'items.'.$index.'.product_id'" :options="$this->productOptions($index)" :selected="(string) $item['product_id']"
                                :placeholder="__('Select product...')" :label="__('Product')" :invalid="$errors->has('items.'.$index.'.product_id')" />
                            @error("items.$index.product_id") <flux:error class="mt-1" :message="$message" /> @enderror
                            @if ($product)
                                @php($incoming = IncomingStock::total($this->incoming[$product->id] ?? []))
                                @php($reserved = $this->reserved[$product->id] ?? '0')
                                @php($check = SalesAvailability::check(filled($item['quantity'] ?? null) && is_numeric($item['quantity']) ? (string) $item['quantity'] : '0', (string) $product->current_stock, $reserved, $incoming))
                                @php($hasReserved = bccomp($reserved, '0', 2) > 0)
                                @php($hasIncoming = bccomp($incoming, '0', 2) > 0)
                                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <div class="flex min-h-11 flex-col justify-center rounded-md border border-zinc-200 bg-zinc-50 px-2.5 py-1 dark:border-white/10 dark:bg-white/5">
                                        <span class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('In Stock') }}</span>
                                        <span class="font-data text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $product->current_stock) }} <span class="text-[11px] font-normal text-zinc-500 dark:text-zinc-400">{{ $product->unit_of_measure }}</span></span>
                                    </div>
                                    <button type="button" @disabled(! $hasReserved) wire:click="showSources({{ $product->id }})"
                                        aria-label="{{ __('Reserved') }} {{ $this->formatQuantity($reserved) }} {{ $product->unit_of_measure }}{{ $hasReserved ? ' — '.__('view orders') : '' }}"
                                        @class([
                                            'group flex min-h-11 items-center gap-2 rounded-md border px-2.5 py-1 text-left transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                                            'cursor-pointer border-amber-200 bg-amber-50 hover:border-amber-300 hover:bg-amber-100 dark:border-amber-500/20 dark:bg-amber-500/10 dark:hover:bg-amber-500/20' => $hasReserved,
                                            'cursor-default border-zinc-200 bg-white dark:border-white/10 dark:bg-transparent' => ! $hasReserved,
                                        ])>
                                        <span class="flex flex-col">
                                            <span @class(['text-[10px] font-semibold uppercase tracking-wider', 'text-amber-800 dark:text-amber-300' => $hasReserved, 'text-zinc-500 dark:text-zinc-400' => ! $hasReserved])>{{ __('Reserved') }}</span>
                                            <span @class(['font-data text-sm font-semibold tabular-nums', 'text-amber-900 dark:text-amber-200' => $hasReserved, 'text-zinc-400 dark:text-zinc-500' => ! $hasReserved])>{{ $this->formatQuantity($reserved) }} <span class="text-[11px] font-normal">{{ $product->unit_of_measure }}</span></span>
                                        </span>
                                        @if ($hasReserved)
                                            <flux:icon.chevron-right variant="micro" class="size-4 text-amber-700 transition-transform group-hover:translate-x-0.5 dark:text-amber-300" />
                                        @endif
                                    </button>
                                    <button type="button" @disabled(! $hasIncoming) wire:click="showSources({{ $product->id }})"
                                        aria-label="{{ __('Incoming') }} {{ $this->formatQuantity($incoming) }} {{ $product->unit_of_measure }}{{ $hasIncoming ? ' — '.__('view documents') : '' }}"
                                        @class([
                                            'group flex min-h-11 items-center gap-2 rounded-md border px-2.5 py-1 text-left transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                                            'cursor-pointer border-blue-200 bg-blue-50 hover:border-blue-300 hover:bg-blue-100 dark:border-blue-500/20 dark:bg-blue-500/10 dark:hover:bg-blue-500/20' => $hasIncoming,
                                            'cursor-default border-zinc-200 bg-white dark:border-white/10 dark:bg-transparent' => ! $hasIncoming,
                                        ])>
                                        <span class="flex flex-col">
                                            <span @class(['text-[10px] font-semibold uppercase tracking-wider', 'text-blue-800 dark:text-blue-300' => $hasIncoming, 'text-zinc-500 dark:text-zinc-400' => ! $hasIncoming])>{{ __('Incoming') }}</span>
                                            <span @class(['font-data text-sm font-semibold tabular-nums', 'text-blue-900 dark:text-blue-200' => $hasIncoming, 'text-zinc-400 dark:text-zinc-500' => ! $hasIncoming])>{{ $this->formatQuantity($incoming) }} <span class="text-[11px] font-normal">{{ $product->unit_of_measure }}</span></span>
                                        </span>
                                        @if ($hasIncoming)
                                            <flux:icon.chevron-right variant="micro" class="size-4 text-blue-700 transition-transform group-hover:translate-x-0.5 dark:text-blue-300" />
                                        @endif
                                    </button>
                                    @if ($check['status'])
                                        <x-material-status :status="$check['status']" :shortage="$check['shortage']" :unit="$product->unit_of_measure" />
                                    @endif
                                </div>
                                @can('costs.view')
                                    @php($preview = $this->costPreview($product->id, $item['quantity'] ?? null))
                                    @php($price = is_numeric($item['unit_price'] ?? null) ? (string) $item['unit_price'] : null)
                                    @php($margin = $preview['average'] !== null && $price !== null ? ProductCost::margin($price, $preview['average']) : null)
                                    @php($under = $margin !== null && bccomp($margin, '0', 1) < 0)
                                    <div @class([
                                        'mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border px-2.5 py-1.5 text-xs',
                                        'border-red-200 bg-red-50 dark:border-red-500/20 dark:bg-red-500/10' => $under,
                                        'border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-white/5' => ! $under,
                                    ])>
                                        @if ($preview['average'] !== null)
                                            <span class="text-zinc-600 dark:text-zinc-300">{{ __('Cost') }} ≈ <span class="font-data font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($preview['average']) }}</span>/{{ $product->unit_of_measure }}</span>
                                            @if ($margin !== null)
                                                @if ($under)
                                                    <span class="inline-flex items-center gap-1 font-semibold text-red-700 dark:text-red-400"><flux:icon.exclamation-triangle variant="micro" class="size-3.5" />{{ __('Below cost') }} · {{ __('margin') }} <span class="font-data tabular-nums">{{ str_replace('.', ',', $margin) }}%</span></span>
                                                @else
                                                    <span class="text-zinc-600 dark:text-zinc-300">{{ __('margin') }} <span class="font-data font-semibold tabular-nums text-green-700 dark:text-green-400">{{ str_replace('.', ',', $margin) }}%</span></span>
                                                @endif
                                            @endif
                                        @else
                                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('No free stock — cost not known yet') }}</span>
                                        @endif
                                        @if (bccomp($preview['uncovered'], '0', 2) > 0 && $preview['average'] !== null)
                                            <span class="text-amber-700 dark:text-amber-400">{{ __(':qty without stock, cost not known yet', ['qty' => $this->formatQuantity($preview['uncovered']).' '.$product->unit_of_measure]) }}</span>
                                        @endif
                                        @if (count($preview['lots']))
                                            <button type="button" wire:click="showSources({{ $product->id }})" class="ml-auto inline-flex items-center gap-1 rounded font-medium text-accent hover:underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent cursor-pointer">
                                                <flux:icon.squares-2x2 variant="micro" class="size-3.5" />{{ trans_choice('View lot|View :count lots', count($preview['lots'])) }}
                                            </button>
                                        @endif
                                    </div>
                                @endcan
                            @endif
                        </div>

                        <div class="min-w-0">
                            <p class="@3xl:hidden mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Qty') }}</p>
                            <flux:input.group>
                                <flux:input size="sm" type="text" inputmode="numeric" pattern="[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false"
                                    wire:model.live.debounce.400ms="items.{{ $index }}.quantity" :aria-label="__('Qty')" />
                                <flux:input.group.suffix class="px-2 text-xs">{{ $product?->unit_of_measure ?? '—' }}</flux:input.group.suffix>
                            </flux:input.group>
                            @error("items.$index.quantity") <flux:error class="mt-1" :message="$message" /> @enderror
                        </div>

                        <div class="min-w-0">
                            <p class="@3xl:hidden mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Selling Price') }}</p>
                            <flux:input.group>
                                <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                                <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false"
                                    wire:model.live.debounce.400ms="items.{{ $index }}.unit_price" :aria-label="__('Selling Price')" />
                            </flux:input.group>
                            @error("items.$index.unit_price") <flux:error class="mt-1" :message="$message" /> @enderror
                        </div>

                        <div class="col-span-2 @3xl:col-span-1 min-w-0 flex items-center justify-between gap-3 border-t border-dashed border-zinc-200 pt-2 @3xl:block @3xl:border-0 @3xl:pt-0 @3xl:text-right dark:border-white/10">
                            <p class="@3xl:hidden text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Subtotal') }}</p>
                            <p class="flex min-h-8 items-center justify-end font-data text-xs font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatRupiah($this->rowSubtotal($item)) }}</p>
                        </div>

                        <div class="col-start-3 row-start-1 @3xl:col-start-5 flex justify-end">
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeItemRow({{ $index }})" :aria-label="__('Remove')" class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 active:scale-[0.95]" />
                        </div>
                    </div>
                @endforeach
            </div>
            @error('items') <flux:error class="mt-2" :message="$message" /> @enderror
        </div>

        <div role="tabpanel" x-show="tab === 'charges'" x-cloak class="mt-4">
            @if ($charges === [])
                <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-200 dark:border-white/10 py-10 text-center">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No other charges — add one for e.g. shipping or packing.') }}</p>
                    <flux:button size="sm" variant="filled" icon="plus" wire:click="addChargeRow" class="active:scale-[0.95]">{{ __('Add charge') }}</flux:button>
                </div>
            @else
                <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
                    <div class="hidden sm:grid grid-cols-[minmax(0,1fr)_11rem_2rem] gap-x-3 px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10 rounded-t-xl">
                        <span>{{ __('Description') }}</span>
                        <span class="text-right">{{ __('Amount') }}</span>
                        <span></span>
                    </div>
                    @foreach ($charges as $index => $charge)
                        <div wire:key="charge-{{ $index }}" class="grid grid-cols-[minmax(0,1fr)_2rem] sm:grid-cols-[minmax(0,1fr)_11rem_2rem] gap-x-3 gap-y-2 items-start px-4 py-3 border-b border-zinc-100 dark:border-white/5 last:border-0">
                            <div class="min-w-0">
                                <flux:input size="sm" wire:model="charges.{{ $index }}.description" :placeholder="__('e.g. Shipping, Packing')" :aria-label="__('Description')" />
                                @error("charges.$index.description") <flux:error class="mt-1" :message="$message" /> @enderror
                            </div>
                            <div class="col-span-2 row-start-2 sm:col-span-1 sm:row-start-1 sm:col-start-2">
                                <flux:input.group>
                                    <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                                    <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false"
                                        wire:model.live.debounce.400ms="charges.{{ $index }}.amount" :aria-label="__('Amount')" />
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
        </div>
    </div>
    <flux:modal name="stock-sources" class="w-full max-w-xl">
        @if ($sources = $this->sourcesProduct)
            @php($unit = $sources->unit_of_measure)
            @php($incomingRows = $this->incoming[$sources->id] ?? [])
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $sources->product_name }}</flux:heading>
                    <flux:subheading>{{ __('Where this product is promised and where more is coming from. Documents open in a new tab, so this order stays as it is.') }}</flux:subheading>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <div class="rounded-md border border-border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-white/5 px-3 py-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('In Stock') }}</p>
                        <p class="font-data text-base font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $sources->current_stock) }}</p>
                    </div>
                    <div class="rounded-md border border-border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10 px-3 py-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-300">{{ __('Reserved') }}</p>
                        <p class="font-data text-base font-semibold tabular-nums text-amber-900 dark:text-amber-200">{{ $this->formatQuantity($this->reserved[$sources->id] ?? '0') }}</p>
                    </div>
                    <div class="rounded-md border border-border-blue-200 bg-blue-50 dark:border-blue-500/20 dark:bg-blue-500/10 px-3 py-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-800 dark:text-blue-300">{{ __('Incoming') }}</p>
                        <p class="font-data text-base font-semibold tabular-nums text-blue-900 dark:text-blue-200">{{ $this->formatQuantity(IncomingStock::total($incomingRows)) }}</p>
                    </div>
                </div>

                @can('costs.view')
                    @php($rowQty = $this->rowQuantity($sources->id))
                    @php($lotPreview = $this->costPreview($sources->id, $rowQty))
                    @php($rowPrice = $this->rowPrice($sources->id))
                    <section aria-labelledby="sources-lots">
                        <h3 id="sources-lots" class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                            <span class="size-2 rounded-full bg-accent"></span>{{ __('Lots this order would use') }}
                        </h3>
                        <p class="mb-2 text-[11px] text-zinc-500 dark:text-zinc-400">
                            {{ __('Oldest first (FIFO), after the stock already promised to other confirmed orders. An estimate — the warehouse takes the actual lots when it delivers.') }}
                        </p>
                        @if (count($lotPreview['lots']))
                            <div class="overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10">
                                <table class="w-full text-xs">
                                    <thead>
                                        <tr class="bg-zinc-50 text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:bg-white/5 dark:text-zinc-400">
                                            <th class="px-3 py-2">{{ __('Lot') }}</th>
                                            <th class="px-3 py-2 text-right">{{ __('Qty') }}</th>
                                            <th class="px-3 py-2 text-right">{{ __('Cost / unit') }}</th>
                                            <th class="px-3 py-2 text-right">{{ __('Value') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                        @foreach ($lotPreview['lots'] as $lot)
                                            <tr>
                                                <td class="px-3 py-2">
                                                    <p class="font-data text-zinc-800 dark:text-zinc-200">{{ $lot['lot_number'] }}</p>
                                                    <p class="text-[10px] text-zinc-500 dark:text-zinc-400">{{ __('received') }} {{ $lot['received_at']->format('d M Y') }}</p>
                                                </td>
                                                <td class="px-3 py-2 text-right font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatQuantity($lot['quantity']) }} {{ $unit }}</td>
                                                <td class="px-3 py-2 text-right font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatRupiah($lot['unit_cost']) }}</td>
                                                <td class="px-3 py-2 text-right font-data tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah(bcmul($lot['quantity'], $lot['unit_cost'], 2)) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="border-t border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-white/5">
                                            <td class="px-3 py-2 font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Average') }}</td>
                                            <td class="px-3 py-2 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity($lotPreview['covered']) }} {{ $unit }}</td>
                                            <td class="px-3 py-2 text-right font-data font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($lotPreview['average']) }}</td>
                                            <td class="px-3 py-2 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">
                                                @if ($rowPrice !== null)
                                                    @php($lotMargin = ProductCost::margin($rowPrice, $lotPreview['average']))
                                                    {{ __('margin') }} <span @class(['font-semibold', 'text-red-600 dark:text-red-400' => bccomp($lotMargin, '0', 1) < 0, 'text-green-700 dark:text-green-400' => bccomp($lotMargin, '0', 1) >= 0])>{{ str_replace('.', ',', $lotMargin) }}%</span>
                                                @endif
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            @if (bccomp($lotPreview['uncovered'], '0', 2) > 0)
                                <p class="mt-1.5 text-[11px] text-amber-700 dark:text-amber-400">{{ __(':qty without stock, cost not known yet', ['qty' => $this->formatQuantity($lotPreview['uncovered']).' '.$unit]) }}</p>
                            @endif
                        @else
                            <p class="rounded-md border border-dashed border-zinc-200 dark:border-white/10 px-3 py-4 text-center text-xs text-zinc-500 dark:text-zinc-400">{{ __('No free stock — cost not known yet') }}</p>
                        @endif
                    </section>
                @endcan

                <section aria-labelledby="sources-reserved">
                    <h3 id="sources-reserved" class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                        <span class="size-2 rounded-full bg-amber-500"></span>{{ __('Reserved for other customers') }}
                    </h3>
                    <x-stock-source-list :rows="$this->reservedOrders" :unit="$unit" :empty="__('No other confirmed orders are waiting for this product.')" />
                </section>

                <section aria-labelledby="sources-incoming">
                    <h3 id="sources-incoming" class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                        <span class="size-2 rounded-full bg-blue-500"></span>{{ __('Incoming') }}
                    </h3>
                    <x-stock-source-list :rows="$incomingRows" :unit="$unit" :empty="__('Nothing is being produced or ordered for this product.')" />
                </section>

                <div class="flex justify-end">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Close') }}</flux:button></flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>
</section>
