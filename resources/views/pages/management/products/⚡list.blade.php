<?php

use App\Actions\MasterData\SaveProductAction;
use App\Enums\ProductType;
use App\Exceptions\InvalidMasterDataException;
use App\Models\Product;
use App\Support\ProductCost;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Products')] class extends Component {
    use WithPagination;

    #[Url]
    public string $type = '';

    #[Url]
    public string $search = '';

    #[Url]
    public bool $showInactive = false;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public bool $editingLocked = false;

    public string $productName = '';

    public string $productType = 'raw_material';

    public string $unit = '';

    public string $safetyStock = '';

    public string $reorderPoint = '';

    public string $sellingPrice = '';

    public bool $isActive = true;

    public function mount(): void
    {
        Gate::authorize('viewAny', Product::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatedProductType(): void
    {
        $fixed = ProductType::tryFrom($this->productType)?->fixedUnit();

        if ($fixed) {
            $this->unit = $fixed;
        } elseif ($this->editingId === null) {
            $this->unit = '';
        }
    }

    protected function filtered(): Builder
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return Product::query()
            ->when(! $this->showInactive, fn (Builder $q) => $q->where('is_active', true))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('product_name', 'like', "%{$term}%")
                ->orWhere('product_code', 'like', "%{$term}%")));
    }

    #[Computed]
    public function products(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->filtered()
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
            ->orderBy('type')
            ->orderBy('product_code')
            ->paginate(20);
    }

    #[Computed]
    public function costs(): array
    {
        return ProductCost::forProducts($this->products->pluck('id'));
    }

    #[Computed]
    public function editingCost(): ?array
    {
        return $this->editingId ? ProductCost::forProducts([$this->editingId])[$this->editingId] : null;
    }

    #[Computed]
    public function typeCounts(): array
    {
        return $this->filtered()->selectRaw('type, count(*) as count')->groupBy('type')->pluck('count', 'type')->all();
    }

    #[Computed]
    public function typeOptions(): array
    {
        return collect(ProductType::cases())->map(fn (ProductType $type) => [
            'id' => $type->value,
            'title' => $type->label(),
            'subtitle' => Product::codePrefix($type).'-…',
            'meta' => null,
            'disabled_reason' => null,
        ])->all();
    }

    public function openCreate(): void
    {
        Gate::authorize('create', Product::class);

        $this->resetForm();
        $this->productType = $this->type !== '' ? $this->type : ProductType::RawMaterial->value;
        $this->unit = ProductType::from($this->productType)->fixedUnit() ?? '';
        $this->modal('product-form')->show();
    }

    public function openEdit(int $id): void
    {
        $product = Product::findOrFail($id);
        Gate::authorize('update', $product);

        $this->resetValidation();
        $this->editingId = $product->id;
        $this->editingLocked = $product->isLocked();
        $this->productName = $product->product_name;
        $this->productType = $product->type->value;
        $this->unit = $product->unit_of_measure;
        $this->safetyStock = $this->formatQuantity((string) $product->safety_stock);
        $this->reorderPoint = $this->formatQuantity((string) $product->reorder_point);
        $this->sellingPrice = $product->selling_price !== null ? bcadd((string) $product->selling_price, '0', 0) : '';
        $this->isActive = $product->is_active;
        $this->modal('product-form')->show();
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'editingLocked', 'productName', 'productType', 'unit', 'safetyStock', 'reorderPoint', 'sellingPrice', 'isActive');
    }

    protected function rules(): array
    {
        return [
            'productName' => ['required', 'string', 'max:255'],
            'productType' => ['required', Rule::enum(ProductType::class)],
            'unit' => [Rule::requiredIf(fn () => ProductType::tryFrom($this->productType)?->fixedUnit() === null), 'nullable', 'string', 'max:20'],
            'safetyStock' => ['nullable', 'numeric', 'min:0'],
            'reorderPoint' => ['nullable', 'numeric', 'min:0'],
            'sellingPrice' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'productName' => __('Product name'),
            'productType' => __('Type'),
            'unit' => __('Unit'),
            'safetyStock' => __('Safety stock'),
            'reorderPoint' => __('Reorder point'),
            'sellingPrice' => __('Selling Price'),
        ];
    }

    public function save(): void
    {
        $this->validate();

        $product = $this->editingId ? Product::findOrFail($this->editingId) : null;
        $product ? Gate::authorize('update', $product) : Gate::authorize('create', Product::class);

        $type = ProductType::from($this->productType);
        $fields = [
            'product_name' => $this->productName,
            'type' => $type,
            'unit_of_measure' => $this->unit,
            'safety_stock' => $this->safetyStock ?: '0',
            'reorder_point' => $this->reorderPoint ?: '0',
            'selling_price' => $type === ProductType::FinishedGoods ? $this->sellingPrice : null,
            'is_active' => $this->isActive,
        ];

        try {
            $saved = app(SaveProductAction::class)->handle($fields, $product);
        } catch (InvalidMasterDataException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $product ? __('Product updated.') : __('Product :code added.', ['code' => $saved->product_code]));
        $this->modal('product-form')->close();
        $this->resetForm();
    }

    public function formatQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
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
        <flux:breadcrumbs.item :href="route('executive.dashboard')" wire:navigate>{{ __('Management') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Products') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Products') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>
        <div class="flex items-center gap-2">
            @can('viewAny', Product::class)
                <flux:button variant="filled" icon="calculator" :href="route('management.products.safety-stock')" wire:navigate class="active:scale-[0.95]">{{ __('Safety Stock & Reorder Point') }}</flux:button>
            @endcan
            @can('create', Product::class)
                <flux:button variant="primary" icon="plus" wire:click="openCreate" class="active:scale-[0.95]">{{ __('New Product') }}</flux:button>
            @endcan
        </div>
    </div>

    <div class="mt-6 flex items-center gap-1.5 overflow-x-auto pb-1 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <button type="button" wire:click="$set('type', '')"
            class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                {{ $type === '' ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
            {{ __('All') }}
            <span class="font-data tabular-nums {{ $type === '' ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ array_sum($this->typeCounts) }}</span>
        </button>
        @foreach (ProductType::cases() as $option)
            <button type="button" wire:click="$set('type', '{{ $option->value }}')"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                    {{ $type === $option->value ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
                {{ $option->label() }}
                <span class="font-data tabular-nums {{ $type === $option->value ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->typeCounts[$option->value] ?? 0 }}</span>
            </button>
        @endforeach
    </div>
    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    <div class="mt-4 flex flex-wrap items-center gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <div class="flex-1 min-w-56">
            <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search name or code...')" />
        </div>
        <flux:switch wire:model.live="showInactive" :label="__('Show inactive')" align="left" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,type,showInactive,gotoPage,previousPage,nextPage" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
        <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Code') }}</th>
                        <th class="py-2 px-3">{{ __('Product') }}</th>
                        <th class="py-2 px-3">{{ __('Type') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('In Stock') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Reorder point') }}</th>
                        @can('costs.view')
                            <th class="py-2 px-3 text-right">{{ __('Average cost') }}</th>
                        @endcan
                        <th class="py-2 px-3 text-right">{{ __('Selling Price') }}</th>
                        <th class="py-2 px-3">{{ __('Status') }}</th>
                        <th class="py-2 px-3 w-12"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->products as $product)
                        <tr wire:key="product-{{ $product->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors {{ $product->is_active ? '' : 'opacity-60' }}">
                            <td class="py-2 px-3 font-data text-accent whitespace-nowrap">{{ $product->product_code }}</td>
                            <td class="py-2 px-3 text-zinc-800 dark:text-zinc-200">{{ $product->product_name }}</td>
                            <td class="py-2 px-3"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap {{ $product->type->badgeClasses() }}">{{ $product->type->label() }}</span></td>
                            <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap {{ $product->isBelowReorderPoint() && bccomp((string) $product->reorder_point, '0', 2) > 0 ? 'text-red-600 dark:text-red-400 font-medium' : 'text-zinc-700 dark:text-zinc-300' }}">{{ $this->formatQuantity((string) $product->current_stock) }} {{ $product->unit_of_measure }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ $this->formatQuantity((string) $product->reorder_point) }} {{ $product->unit_of_measure }}</td>
                            @php($cost = $this->costs[$product->id] ?? null)
                            @php($costValue = $cost['average'] ?? $cost['last'] ?? null)
                            @can('costs.view')
                                <td class="py-2 px-3 text-right whitespace-nowrap">
                                    @if ($costValue !== null)
                                        <span class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatRupiah($costValue) }}</span>
                                        @if ($cost['average'] === null)
                                            <span class="block text-[10px] text-zinc-500 dark:text-zinc-400">{{ __('last lot, no stock') }}</span>
                                        @endif
                                    @else
                                        <span class="text-zinc-400 dark:text-zinc-500">—</span>
                                    @endif
                                </td>
                            @endcan
                            <td class="py-2 px-3 text-right whitespace-nowrap">
                                <span class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $product->selling_price !== null ? $this->formatRupiah((string) $product->selling_price) : '—' }}</span>
                                @can('costs.view')
                                    @if ($product->selling_price !== null && $costValue !== null)
                                        @php($margin = ProductCost::margin((string) $product->selling_price, $costValue))
                                        <span @class(['block font-data text-[10px] tabular-nums', 'text-red-600 dark:text-red-400 font-semibold' => bccomp($margin, '0', 1) < 0, 'text-zinc-500 dark:text-zinc-400' => bccomp($margin, '0', 1) >= 0])>{{ __('margin') }} {{ str_replace('.', ',', $margin) }}%</span>
                                    @endif
                                @endcan
                            </td>
                            <td class="py-2 px-3">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $product->is_active ? 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20' : 'bg-zinc-100 text-zinc-500 border-zinc-200 dark:bg-white/5 dark:text-zinc-500 dark:border-white/10' }}">{{ $product->is_active ? __('Active') : __('Inactive') }}</span>
                            </td>
                            <td class="py-2 px-3 text-right">
                                @can('update', $product)
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEdit({{ $product->id }})" :aria-label="__('Edit :name', ['name' => $product->product_name])" class="active:scale-[0.95]" />
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ auth()->user()->can('costs.view') ? 9 : 8 }}" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No products match your filters.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $this->products->links() }}</div>
    </div>

    <flux:modal name="product-form" focusable class="max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit Product') : __('New Product') }}</flux:heading>
                <flux:subheading>
                    @if (! $editingId)
                        {{ __('The product code is generated from the type.') }}
                    @elseif ($editingLocked)
                        {{ __('This product already has stock or documents, so its type and unit are locked.') }}
                    @endif
                </flux:subheading>
            </div>

            <flux:input wire:model="productName" :label="__('Product name')" />

            <div class="grid grid-cols-1 sm:grid-cols-2 items-start gap-4">
                <flux:field>
                    <flux:label>{{ __('Type') }}</flux:label>
                    @if ($editingLocked)
                        <flux:input :value="ProductType::from($productType)->label()" disabled />
                    @else
                        <x-picker model="productType" size="md" :options="$this->typeOptions" :selected="$productType" :placeholder="__('Select type...')" :label="__('Type')" :invalid="$errors->has('productType')" />
                    @endif
                    @error('productType') <flux:error class="mt-1" :message="$message" /> @enderror
                </flux:field>
                @php($fixedUnit = ProductType::tryFrom($productType)?->fixedUnit())
                <flux:field>
                    <flux:label>{{ __('Unit') }}</flux:label>
                    <flux:input wire:model="unit" :placeholder="__('e.g. Kg, Liter')" :disabled="$editingLocked || $fixedUnit" />
                    @if ($fixedUnit && ! $editingLocked)
                        <flux:description>{{ __('WIP and finished goods are always counted in :unit.', ['unit' => $fixedUnit]) }}</flux:description>
                    @endif
                    @error('unit') <flux:error :message="$message" /> @enderror
                </flux:field>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 items-start gap-4">
                <flux:input wire:model="safetyStock" :label="__('Safety stock')" inputmode="decimal" placeholder="0" />
                <flux:input wire:model="reorderPoint" :label="__('Reorder point')" inputmode="decimal" placeholder="0" />
            </div>

            @if ($productType === ProductType::FinishedGoods->value)
                <flux:field>
                    <flux:label>{{ __('Selling Price') }}</flux:label>
                    <flux:input.group>
                        <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                        <flux:input wire:model.live.debounce.400ms="sellingPrice" inputmode="decimal" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false" />
                    </flux:input.group>
                    <flux:description>{{ __('Pre-filled on new sales orders; can be changed per order.') }}</flux:description>
                    @error('sellingPrice') <flux:error :message="$message" /> @enderror
                    @can('costs.view')
                        @php($editCost = $this->editingCost)
                        @php($editCostValue = $editCost['average'] ?? $editCost['last'] ?? null)
                        <div class="mt-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 text-xs dark:border-white/10 dark:bg-white/5" aria-live="polite">
                            @if ($editCostValue !== null)
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-zinc-600 dark:text-zinc-300">
                                        {{ $editCost['average'] !== null ? __('Average cost of stock') : __('Cost of the last lot') }}
                                        @if ($editCost['average'] !== null)
                                            <span class="text-zinc-400">({{ $this->formatQuantity($editCost['stock']) }} {{ $unit }})</span>
                                        @endif
                                    </span>
                                    <span class="font-data font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($editCostValue) }}</span>
                                </div>
                                @if (is_numeric($sellingPrice) && (float) $sellingPrice > 0)
                                    @php($editMargin = ProductCost::margin((string) $sellingPrice, $editCostValue))
                                    <div class="mt-1.5 flex items-center justify-between gap-3">
                                        <span class="text-zinc-600 dark:text-zinc-300">{{ __('Margin at this price') }}</span>
                                        @if (bccomp($editMargin, '0', 1) < 0)
                                            <span class="inline-flex items-center gap-1 font-data font-semibold tabular-nums text-red-600 dark:text-red-400"><flux:icon.exclamation-triangle variant="micro" class="size-3.5" />{{ str_replace('.', ',', $editMargin) }}% · {{ __('below cost') }}</span>
                                        @else
                                            <span class="font-data font-semibold tabular-nums text-green-700 dark:text-green-400">{{ str_replace('.', ',', $editMargin) }}%</span>
                                        @endif
                                    </div>
                                @endif
                            @else
                                <p class="text-zinc-500 dark:text-zinc-400">{{ __('No cost yet — this product has never been produced.') }}</p>
                            @endif
                        </div>
                    @endcan
                </flux:field>
            @endif

            <flux:switch wire:model="isActive" :label="__('Active')" :description="__('Inactive products disappear from pickers but stay in history.')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
