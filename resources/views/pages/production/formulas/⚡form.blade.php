<?php

use App\Actions\MasterData\SaveProductionFormulaAction;
use App\Enums\ProductType;
use App\Exceptions\InvalidMasterDataException;
use App\Models\Product;
use App\Models\ProductionFormula;
use App\Models\WorkCenter;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Production Formula')] class extends Component {
    #[Locked]
    public ?int $formulaId = null;

    #[Locked]
    public string $formulaCode = '';

    public string $productId = '';

    public string $workCenterId = '';

    public string $outputQuantity = '';

    public bool $isActive = true;

    public array $materials = [];

    public function mount(?ProductionFormula $formula = null): void
    {
        if ($formula?->exists) {
            Gate::authorize('update', $formula);

            $this->formulaId = $formula->id;
            $this->formulaCode = $formula->formula_code;
            $this->productId = (string) $formula->product_id;
            $this->workCenterId = (string) $formula->work_center_id;
            $this->outputQuantity = $this->formatQuantity((string) $formula->output_quantity);
            $this->isActive = $formula->is_active;
            $this->materials = $formula->items->map(fn ($item) => [
                'product_id' => (string) $item->material_product_id,
                'quantity' => $this->formatQuantity((string) $item->quantity),
            ])->all();

            return;
        }

        Gate::authorize('create', ProductionFormula::class);
        $this->addMaterialRow();
    }

    public function addMaterialRow(): void
    {
        $this->materials[] = ['product_id' => '', 'quantity' => ''];
    }

    public function removeMaterialRow(int $index): void
    {
        unset($this->materials[$index]);
        $this->materials = array_values($this->materials);
    }

    #[Computed]
    public function product(): ?Product
    {
        return $this->productId ? Product::find($this->productId) : null;
    }

    #[Computed]
    public function productOptions(): array
    {
        $withFormula = ProductionFormula::pluck('product_id')->all();

        return Product::active()
            ->whereIn('type', [ProductType::Wip->value, ProductType::FinishedGoods->value])
            ->orderBy('type')->orderBy('product_name')->get()
            ->map(fn (Product $product) => $product->toPickerOption(
                disabledReason: in_array($product->id, $withFormula, true) ? __('Has a formula') : null,
            ))->all();
    }

    #[Computed]
    public function workCenterOptions(): array
    {
        return WorkCenter::orderBy('name')->get()->map(fn (WorkCenter $center) => [
            'id' => $center->id,
            'title' => __($center->name),
            'subtitle' => $center->code,
            'meta' => null,
            'disabled_reason' => null,
        ])->all();
    }

    #[Computed]
    public function materialProducts(): Collection
    {
        return Product::whereIn('type', [ProductType::RawMaterial->value, ProductType::Wip->value])->orderBy('type')->orderBy('product_name')->get();
    }

    public function materialOptions(int $index): array
    {
        $taken = collect($this->materials)->except($index)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();
        $current = (int) ($this->materials[$index]['product_id'] ?? 0);

        return $this->materialProducts
            ->filter(fn (Product $product) => ($product->is_active || $product->id === $current) && $product->id !== (int) $this->productId)
            ->map(fn (Product $product) => $product->toPickerOption(
                disabledReason: in_array($product->id, $taken, true) ? __('Already chosen') : null,
            ))->values()->all();
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
            'productId' => ['required', 'exists:products,id'],
            'workCenterId' => ['required', 'exists:work_centers,id'],
            'outputQuantity' => ['required', 'integer', 'min:1'],
            'materials' => ['required', 'array', 'min:1'],
            'materials.*.product_id' => ['required', 'distinct', 'exists:products,id'],
            'materials.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }

    protected function messages(): array
    {
        return [
            'materials.*.product_id.distinct' => __('This material is already listed.'),
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'productId' => __('Product'),
            'workCenterId' => __('Work Center'),
            'outputQuantity' => __('Output per batch'),
            'materials' => __('Materials'),
            'materials.*.product_id' => __('Material'),
            'materials.*.quantity' => __('Quantity'),
        ];
    }

    public function save(): void
    {
        $formula = $this->formulaId ? ProductionFormula::findOrFail($this->formulaId) : null;
        $formula ? Gate::authorize('update', $formula) : Gate::authorize('create', ProductionFormula::class);

        $this->materials = array_values(array_filter($this->materials, fn (array $row) => filled($row['product_id']) || filled($row['quantity'])));

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($this->materials === []) {
                $this->addMaterialRow();
            }
            Flux::toast(variant: 'danger', text: __('Please fix the highlighted fields before submitting.'));
            throw $e;
        }

        try {
            $formula = app(SaveProductionFormulaAction::class)->handle(
                (int) $this->productId,
                (int) $this->workCenterId,
                $this->outputQuantity,
                $this->isActive,
                collect($this->materials)->mapWithKeys(fn (array $row) => [(int) $row['product_id'] => (string) $row['quantity']])->all(),
                Auth::id(),
                $formula,
            );
        } catch (InvalidMasterDataException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Formula :code saved. New work orders use it; existing ones keep their own plan.', ['code' => $formula->formula_code]));
        $this->redirectRoute('production.formulas.list', navigate: true);
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

@php($unit = $this->product?->unit_of_measure ?? '—')
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('production.dashboard')" wire:navigate>{{ __('Production') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('production.formulas.list')" wire:navigate>{{ __('Production Formulas') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $formulaId ? $formulaCode : __('Create') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ $formulaId ? __('Edit Formula') : __('New Formula') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                @if ($formulaId)
                    <span class="font-data text-accent">{{ $formulaCode }}</span> · {{ __('Changes apply to new work orders; existing ones keep their own plan.') }}
                @else
                    {{ __('The formula code is generated from the product.') }}
                @endif
            </p>
        </div>
        <flux:button variant="primary" icon="document-check" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">{{ __('Save') }}</flux:button>
    </div>

    <div class="mt-6 rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-5 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="grid grid-cols-1 md:grid-cols-3 items-start gap-4">
            <flux:field>
                <flux:label>{{ __('Product made') }}</flux:label>
                @if ($formulaId)
                    <flux:input :value="$this->product?->product_name" disabled />
                @else
                    <x-picker model="productId" size="md" :options="$this->productOptions" :selected="(string) $productId"
                        :placeholder="__('Select WIP or finished good...')" :label="__('Product made')" :invalid="$errors->has('productId')" />
                @endif
                @error('productId') <flux:error class="mt-1" :message="$message" /> @enderror
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Work Center') }}</flux:label>
                <x-picker model="workCenterId" size="md" :options="$this->workCenterOptions" :selected="(string) $workCenterId"
                    :placeholder="__('Select work center...')" :label="__('Work Center')" :invalid="$errors->has('workCenterId')" />
                @error('workCenterId') <flux:error class="mt-1" :message="$message" /> @enderror
            </flux:field>
            <flux:field>
                <flux:label>{{ __('Output per batch') }}</flux:label>
                <flux:input.group>
                    <flux:input wire:model="outputQuantity" inputmode="numeric" placeholder="100" input:class="text-right font-data tabular-nums" />
                    <flux:input.group.suffix class="px-2 text-xs">{{ $unit }}</flux:input.group.suffix>
                </flux:input.group>
                <flux:description>{{ __('The materials below make this many units.') }}</flux:description>
                @error('outputQuantity') <flux:error :message="$message" /> @enderror
            </flux:field>
        </div>
        <div class="mt-4">
            <flux:switch wire:model="isActive" :label="__('Active')" :description="__('Only active formulas can be chosen for new work orders.')" />
        </div>
    </div>

    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Materials') }}</h2>
            <flux:button size="sm" variant="ghost" icon="plus" wire:click="addMaterialRow" class="active:scale-[0.95]">{{ __('Add material') }}</flux:button>
        </div>
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <div class="hidden sm:grid grid-cols-[minmax(0,1fr)_11rem_2rem] gap-x-3 px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10 rounded-t-xl">
                <span>{{ __('Material') }}</span>
                <span class="text-right">{{ __('Quantity per batch') }}</span>
                <span></span>
            </div>
            @foreach ($materials as $index => $row)
                @php($material = $this->materialProducts->firstWhere('id', (int) ($row['product_id'] ?? 0)))
                <div wire:key="mat-{{ $index }}" class="grid grid-cols-[minmax(0,1fr)_2rem] sm:grid-cols-[minmax(0,1fr)_11rem_2rem] gap-x-3 gap-y-2 items-start px-4 py-3 border-b border-zinc-100 dark:border-white/5 last:border-0">
                    <div class="min-w-0">
                        <x-picker :model="'materials.'.$index.'.product_id'" :options="$this->materialOptions($index)" :selected="(string) $row['product_id']"
                            :placeholder="__('Select material...')" :label="__('Material')" :invalid="$errors->has('materials.'.$index.'.product_id')" />
                        @error("materials.$index.product_id") <flux:error class="mt-1" :message="$message" /> @enderror
                    </div>
                    <div class="col-span-2 row-start-2 sm:col-span-1 sm:row-start-1 sm:col-start-2">
                        <flux:input.group>
                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false"
                                wire:model="materials.{{ $index }}.quantity" :aria-label="__('Quantity per batch')" />
                            <flux:input.group.suffix class="px-2 text-xs">{{ $material?->unit_of_measure ?? '—' }}</flux:input.group.suffix>
                        </flux:input.group>
                        @error("materials.$index.quantity") <flux:error class="mt-1" :message="$message" /> @enderror
                    </div>
                    <div class="col-start-2 row-start-1 sm:col-start-3 flex justify-end">
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeMaterialRow({{ $index }})" :aria-label="__('Remove')" class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 active:scale-[0.95]" />
                    </div>
                </div>
            @endforeach
        </div>
        @error('materials') <flux:error class="mt-2" :message="$message" /> @enderror
    </div>
</section>
