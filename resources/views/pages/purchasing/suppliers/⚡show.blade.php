<?php

use App\Actions\MasterData\SaveSupplierAction;
use App\Actions\MasterData\SyncSupplierCatalogAction;
use App\Enums\ProductType;
use App\Exceptions\InvalidMasterDataException;
use App\Models\Product;
use App\Models\Supplier;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Supplier')] class extends Component {
    public Supplier $supplier;

    #[Url]
    public string $tab = 'profile';

    public string $supplierName = '';

    public string $contactPerson = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public bool $isActive = true;

    public array $catalog = [];

    public function mount(): void
    {
        Gate::authorize('view', $this->supplier);

        $this->fillProfile();
        $this->fillCatalog();
    }

    protected function fillProfile(): void
    {
        $this->supplierName = $this->supplier->supplier_name;
        $this->contactPerson = (string) $this->supplier->contact_person;
        $this->phone = (string) $this->supplier->phone;
        $this->email = (string) $this->supplier->email;
        $this->address = (string) $this->supplier->address;
        $this->isActive = $this->supplier->is_active;
    }

    protected function fillCatalog(): void
    {
        $this->catalog = $this->supplier->products()->orderBy('product_name')->get()->map(fn (Product $product) => [
            'product_id' => (string) $product->id,
            'price' => bcadd((string) $product->pivot->price, '0', 0),
        ])->all();
    }

    #[Computed]
    public function rawMaterials(): Collection
    {
        return Product::where('type', ProductType::RawMaterial->value)->orderBy('product_name')->get();
    }

    public function productOptions(int $index): array
    {
        $taken = collect($this->catalog)->except($index)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();
        $current = (int) ($this->catalog[$index]['product_id'] ?? 0);

        return $this->rawMaterials
            ->filter(fn (Product $product) => $product->is_active || $product->id === $current)
            ->map(fn (Product $product) => $product->toPickerOption(
                $product->unit_of_measure,
                in_array($product->id, $taken, true) ? __('Already chosen') : null,
            ))->values()->all();
    }

    #[Computed]
    public function purchaseOrders(): Collection
    {
        return $this->supplier->purchaseOrders()->latest('id')->limit(20)->get();
    }

    public function addCatalogRow(): void
    {
        $this->catalog[] = ['product_id' => '', 'price' => ''];
    }

    public function removeCatalogRow(int $index): void
    {
        unset($this->catalog[$index]);
        $this->catalog = array_values($this->catalog);
    }

    public function saveProfile(): void
    {
        Gate::authorize('update', $this->supplier);

        $this->validate([
            'supplierName' => ['required', 'string', 'max:255'],
            'contactPerson' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'supplierName' => __('Supplier name'),
            'contactPerson' => __('Contact person'),
            'phone' => __('Phone'),
            'email' => __('Email'),
            'address' => __('Address'),
        ]);

        app(SaveSupplierAction::class)->handle([
            'supplier_name' => $this->supplierName,
            'contact_person' => $this->contactPerson,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'is_active' => $this->isActive,
        ], $this->supplier);

        Flux::toast(variant: 'success', text: __('Supplier updated.'));
    }

    public function saveCatalog(): void
    {
        Gate::authorize('update', $this->supplier);

        $this->catalog = array_values(array_filter($this->catalog, fn (array $row) => filled($row['product_id']) || filled($row['price'])));

        try {
            $this->validate([
                'catalog.*.product_id' => ['required', 'distinct', 'exists:products,id'],
                'catalog.*.price' => ['required', 'numeric', 'gt:0'],
            ], [
                'catalog.*.product_id.distinct' => __('This product is already in the catalog.'),
            ], [
                'catalog.*.product_id' => __('Product'),
                'catalog.*.price' => __('Unit Price'),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->tab = 'catalog';
            throw $e;
        }

        try {
            app(SyncSupplierCatalogAction::class)->handle(
                $this->supplier,
                collect($this->catalog)->mapWithKeys(fn (array $row) => [(int) $row['product_id'] => (string) $row['price']])->all(),
            );
        } catch (InvalidMasterDataException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->supplier->refresh();
        $this->fillCatalog();
        Flux::toast(variant: 'success', text: __('Catalog saved. New purchase orders use these prices.'));
    }

    public function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
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
        <flux:breadcrumbs.item :href="route('purchasing.suppliers.list')" wire:navigate>{{ __('Suppliers') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $supplier->supplier_code }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $supplier->supplier_name }}</h1>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $supplier->is_active ? 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20' : 'bg-zinc-100 text-zinc-500 border-zinc-200 dark:bg-white/5 dark:text-zinc-500 dark:border-white/10' }}">{{ $supplier->is_active ? __('Active') : __('Inactive') }}</span>
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5"><span class="font-data text-accent">{{ $supplier->supplier_code }}</span></p>
        </div>
        <flux:button variant="ghost" icon="arrow-left" :href="route('purchasing.suppliers.list')" wire:navigate class="active:scale-[0.95]">{{ __('Back to List') }}</flux:button>
    </div>

    <div class="mt-6 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;" x-data="{ tab: $wire.entangle('tab') }">
        @php($catalogErrors = collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'catalog')))
        <x-tab-bar :label="__('Supplier details')" :tabs="[
            'profile' => ['label' => __('Profile')],
            'catalog' => ['label' => __('Product Catalog'), 'count' => count($catalog), 'error' => $catalogErrors],
            'orders' => ['label' => __('Purchase Orders'), 'count' => $supplier->purchaseOrders()->count()],
        ]" />

        <div role="tabpanel" x-show="tab === 'profile'" class="mt-4">
            <form wire:submit="saveProfile" class="max-w-2xl rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-5 space-y-5">
                <flux:input wire:model="supplierName" :label="__('Supplier name')" />
                <div class="grid grid-cols-1 sm:grid-cols-2 items-start gap-4">
                    <flux:input wire:model="contactPerson" :label="__('Contact person')" />
                    <flux:input wire:model="phone" :label="__('Phone')" type="tel" />
                </div>
                <flux:input wire:model="email" :label="__('Email')" type="email" />
                <flux:textarea wire:model="address" :label="__('Address')" rows="2" />
                <flux:switch wire:model="isActive" :label="__('Active')" :description="__('An inactive supplier cannot be chosen on new purchase orders.')" />
                <div class="flex justify-end">
                    <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="saveProfile" class="active:scale-[0.95]">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </div>

        <div role="tabpanel" x-show="tab === 'catalog'" x-cloak class="mt-4">
            <div class="flex items-center justify-between gap-3 mb-3">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Raw materials this supplier sells and their price — the purchase order form offers only these.') }}</p>
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="addCatalogRow" class="active:scale-[0.95]">{{ __('Add product') }}</flux:button>
            </div>
            @if ($catalog === [])
                <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-200 dark:border-white/10 py-10 text-center">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No products yet — a purchase order cannot be made for this supplier until it has some.') }}</p>
                    <flux:button size="sm" variant="filled" icon="plus" wire:click="addCatalogRow" class="active:scale-[0.95]">{{ __('Add product') }}</flux:button>
                </div>
            @else
                <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
                    <div class="hidden sm:grid grid-cols-[minmax(0,1fr)_12rem_2rem] gap-x-3 px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10 rounded-t-xl">
                        <span>{{ __('Product') }}</span>
                        <span class="text-right">{{ __('Unit Price') }}</span>
                        <span></span>
                    </div>
                    @foreach ($catalog as $index => $row)
                        @php($product = $this->rawMaterials->firstWhere('id', (int) ($row['product_id'] ?? 0)))
                        <div wire:key="cat-{{ $index }}" class="grid grid-cols-[minmax(0,1fr)_2rem] sm:grid-cols-[minmax(0,1fr)_12rem_2rem] gap-x-3 gap-y-2 items-start px-4 py-3 border-b border-zinc-100 dark:border-white/5 last:border-0">
                            <div class="min-w-0">
                                <x-picker :model="'catalog.'.$index.'.product_id'" :options="$this->productOptions($index)" :selected="(string) $row['product_id']"
                                    :placeholder="__('Select product...')" :label="__('Product')" :invalid="$errors->has('catalog.'.$index.'.product_id')" />
                                @error("catalog.$index.product_id") <flux:error class="mt-1" :message="$message" /> @enderror
                                @if ($product && ! $product->is_active)
                                    <p class="mt-1 text-[11px] text-amber-700 dark:text-amber-400">{{ __('This product is inactive.') }}</p>
                                @endif
                            </div>
                            <div class="col-span-2 row-start-2 sm:col-span-1 sm:row-start-1 sm:col-start-2">
                                <flux:input.group>
                                    <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                                    <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false"
                                        wire:model="catalog.{{ $index }}.price" :aria-label="__('Unit Price')" />
                                    <flux:input.group.suffix class="px-2 text-xs">/{{ $product?->unit_of_measure ?? '—' }}</flux:input.group.suffix>
                                </flux:input.group>
                                @error("catalog.$index.price") <flux:error class="mt-1" :message="$message" /> @enderror
                            </div>
                            <div class="col-start-2 row-start-1 sm:col-start-3 flex justify-end">
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeCatalogRow({{ $index }})" :aria-label="__('Remove')" class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 active:scale-[0.95]" />
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="mt-4 flex justify-end">
                <flux:button variant="primary" wire:click="saveCatalog" wire:loading.attr="disabled" wire:target="saveCatalog" class="active:scale-[0.95]">{{ __('Save catalog') }}</flux:button>
            </div>
        </div>

        <div role="tabpanel" x-show="tab === 'orders'" x-cloak class="mt-4">
            @if ($this->purchaseOrders->isEmpty())
                <div class="rounded-md border border-dashed border-zinc-200 dark:border-white/10 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No purchase orders yet.') }}</div>
            @else
                <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach ($this->purchaseOrders as $order)
                        <a wire:key="po-{{ $order->id }}" href="{{ route('purchasing.orders.show', $order) }}" wire:navigate class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-xs hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                            <span class="font-data text-accent">{{ $order->po_number }}</span>
                            <span class="font-data tabular-nums text-zinc-500 dark:text-zinc-400">{{ $order->order_date?->format('d M Y') }}</span>
                            <span class="font-data tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $order->total_amount) }}</span>
                            <span class="text-zinc-600 dark:text-zinc-400">{{ $order->status->label() }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
