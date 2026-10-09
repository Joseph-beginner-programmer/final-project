<?php

use App\Actions\Production\CreateWorkOrderAction;
use App\Actions\Production\UpdateWorkOrderAction;
use App\DTO\Production\CreateWorkOrderData;
use App\Enums\ProductType;
use App\Exceptions\InactiveEmployeeException;
use App\Exceptions\InactiveProductionFormulaException;
use App\Exceptions\InvalidPlannedDateRangeException;
use App\Exceptions\MissingLaborRateException;
use App\Exceptions\MissingOverheadRateException;
use App\Exceptions\PlannedStartDateInPastException;
use App\Exceptions\WorkOrderNotEditableException;
use App\Exceptions\WorkOrderRequiresLaborException;
use App\Models\Employee;
use App\Models\ProductionFormula;
use App\Models\WorkOrder;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Create Work Order')] class extends Component {
    /** set when editing a Draft WO — the same form serves create and edit */
    #[Locked]
    public ?int $workOrderId = null;

    #[Locked]
    public string $workOrderNumber = '';

    public string $productionFormulaId = '';

    public string $quantityTarget = '';

    public string $plannedStartDate = '';

    public string $plannedEndDate = '';

    public array $labors = [];

    public string $plannedMachineHours = '';

    public function mount(?WorkOrder $workOrder = null): void
    {
        if ($workOrder?->exists) {
            Gate::authorize('update', $workOrder);

            $this->workOrderId = $workOrder->id;
            $this->workOrderNumber = $workOrder->wo_number;
            $this->productionFormulaId = (string) $workOrder->production_formula_id;
            $this->quantityTarget = $this->formatQuantity((string) $workOrder->quantity_target);
            $this->plannedStartDate = $workOrder->planned_start_date->toDateString();
            $this->plannedEndDate = $workOrder->planned_end_date->toDateString();
            $this->plannedMachineHours = $this->formatQuantity((string) $workOrder->planned_machine_hours);
            $this->labors = $workOrder->labors->map(fn ($labor) => [
                'employee_id' => (string) $labor->employee_id,
                'planned_hours' => $this->formatQuantity((string) $labor->planned_hours),
            ])->all();

            return;
        }

        Gate::authorize('create', WorkOrder::class);

        $this->plannedStartDate = now()->toDateString();
        $this->addLaborRow();
    }

    public function addLaborRow(): void
    {
        $this->labors[] = [
            'employee_id' => '',
            'planned_hours' => '',
        ];
    }

    public function removeLaborRow(int $index): void
    {
        unset($this->labors[$index]);

        $this->labors = array_values($this->labors);
    }

    /**
     * Active employees, each with only the rate in effect today eager-loaded
     * (one query for all rates instead of one per employee).
     */
    #[Computed]
    public function employees(): Collection
    {
        $today = now()->toDateString();

        return Employee::active()
            ->with(['laborRates' => fn ($query) => $query
                ->whereDate('effective_from', '<=', $today)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
                ->orderByDesc('effective_from')])
            ->orderBy('name')
            ->get();
    }

    /**
     * Option rows for one labor row's <x-picker> (a Blade component can't call this component's
     * methods, so everything it shows is prepared here). Workers already chosen in another row,
     * or without an hourly rate, are listed but unavailable — with the reason.
     */
    public function workerOptions(int $index): array
    {
        $taken = collect($this->labors)->except($index)->pluck('employee_id')->filter()->map(fn ($id) => (int) $id)->all();

        return $this->employees->map(function (Employee $employee) use ($taken) {
            $rate = $employee->laborRates->first()?->hourly_rate;

            return [
                'id' => $employee->id,
                'title' => $employee->name,
                'subtitle' => $employee->employee_code,
                'meta' => $rate !== null ? $this->formatRupiah((string) $rate).'/'.__('hrs') : null,
                'disabled_reason' => match (true) {
                    in_array($employee->id, $taken, true) => __('Already chosen'),
                    $rate === null => __('no rate'),
                    default => null,
                },
            ];
        })->all();
    }

    public function currentRateFor(mixed $employeeId): ?string
    {
        $rate = $this->employees->firstWhere('id', (int) $employeeId)?->laborRates->first()?->hourly_rate;

        return $rate !== null ? (string) $rate : null;
    }

    public function laborRowCost(array $row): string
    {
        $rate = $this->currentRateFor($row['employee_id'] ?? null);
        $hours = is_numeric($row['planned_hours'] ?? null) ? (string) $row['planned_hours'] : '0';

        return $rate ? bcmul($hours, $rate, 2) : '0';
    }

    public function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    public function isLaborStepComplete(): bool
    {
        return collect($this->labors)->contains(
            fn (array $row) => filled($row['employee_id'] ?? null) && is_numeric($row['planned_hours'] ?? null) && $row['planned_hours'] > 0
        );
    }

    #[Computed]
    public function formulas(): Collection
    {
        return ProductionFormula::query()
            ->where('is_active', true)
            ->with(['product', 'workCenter'])
            ->withCount('items')
            ->orderBy('formula_code')
            ->get();
    }

    /**
     * Formulas grouped by the work center that runs them — how production thinks about recipes.
     */
    #[Computed]
    public function formulasByWorkCenter(): Collection
    {
        return $this->formulas->groupBy(fn (ProductionFormula $formula) => $formula->workCenter->name);
    }

    public function productTypeChipClasses(ProductType $type): string
    {
        return match ($type) {
            ProductType::Wip => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-400 dark:border-blue-500/20',
            ProductType::FinishedGoods => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
            ProductType::RawMaterial => 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:border-white/10',
        };
    }

    #[Computed]
    public function selectedFormula(): ?ProductionFormula
    {
        return $this->productionFormulaId
            ? ProductionFormula::with(['product', 'workCenter', 'items.materialProduct'])->find($this->productionFormulaId)
            : null;
    }

    /**
     * The selected formula's work-center overhead rate in effect today — what the WO will copy.
     */
    #[Computed]
    public function overheadRate(): ?string
    {
        $rate = $this->selectedFormula?->workCenter->overheadRateOn(now())?->rate_per_hour;

        return $rate !== null ? (string) $rate : null;
    }

    #[Computed]
    public function plannedOverheadCost(): string
    {
        $hours = is_numeric($this->plannedMachineHours) ? (string) $this->plannedMachineHours : '0';

        return $this->overheadRate ? bcmul($hours, $this->overheadRate, 2) : '0';
    }

    public function isMachineStepComplete(): bool
    {
        return is_numeric($this->plannedMachineHours) && $this->plannedMachineHours > 0;
    }

    /**
     * The formula's recipe scaled to the typed target. Read-only preview: the BOM is a
     * template — what's actually consumed gets recorded later on material issues.
     */
    #[Computed]
    public function materialRequirements(): Collection
    {
        $formula = $this->selectedFormula;

        if (!$formula) {
            return collect();
        }

        $target = $this->hasValidTarget() ? $this->quantityTarget : '0';

        return $formula->items->map(function ($item) use ($formula, $target) {
            $required = $formula->scaleMaterialQuantity((string) $item->quantity, $target);

            return [
                'product' => $item->materialProduct,
                'per_batch' => (string) $item->quantity,
                'required' => $required,
                'is_short' => bccomp($required, (string) $item->materialProduct->current_stock, 2) > 0,
            ];
        });
    }

    #[Computed]
    public function shortMaterialCount(): int
    {
        return $this->materialRequirements->where('is_short', true)->count();
    }

    public function hasValidTarget(): bool
    {
        return ctype_digit($this->quantityTarget) && (int) $this->quantityTarget > 0;
    }

    public function formatQuantity(string $quantity): string
    {
        if (! str_contains($quantity, '.')) {
            return $quantity;
        }

        return rtrim(rtrim($quantity, '0'), '.');
    }

    public function isFormulaStepComplete(): bool
    {
        return filled($this->productionFormulaId) && $this->hasValidTarget();
    }

    public function isScheduleStepComplete(): bool
    {
        return filled($this->plannedStartDate) && filled($this->plannedEndDate);
    }

    protected function rules(): array
    {
        return [
            'productionFormulaId' => ['required', 'exists:production_formulas,id'],
            // production output is always whole units (Session 14) — fractional quantities live on the material side only
            'quantityTarget' => ['required', 'integer', 'min:1'],
            'plannedStartDate' => ['required', 'date', 'after_or_equal:today'],
            'plannedEndDate' => ['required', 'date', 'after_or_equal:plannedStartDate'],
            // <<include>> Mengalokasi Biaya Tenaga Kerja — at least one worker, each listed once
            'labors' => ['required', 'array', 'min:1'],
            'labors.*.employee_id' => ['required', 'distinct', 'exists:employees,id'],
            'labors.*.planned_hours' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'plannedMachineHours' => ['required', 'numeric', 'gt:0', 'max:10000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'labors.required' => __('Assign at least one worker to this work order.'),
            'labors.*.employee_id.distinct' => __('This worker is already listed.'),
            // the rule's default message would print the literal word "today"
            'plannedStartDate.after_or_equal' => __('The planned start date cannot be in the past.'),
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'productionFormulaId' => __('Formula'),
            'quantityTarget' => __('Target Quantity'),
            'plannedStartDate' => __('Planned Start'),
            'plannedEndDate' => __('Planned End'),
            'labors' => __('Workers'),
            'labors.*.employee_id' => __('Worker'),
            'labors.*.planned_hours' => __('Planned Hours'),
            'plannedMachineHours' => __('Machine Hours'),
        ];
    }

    public function create(): void
    {
        $existing = $this->workOrderId ? WorkOrder::findOrFail($this->workOrderId) : null;
        $existing ? Gate::authorize('update', $existing) : Gate::authorize('create', WorkOrder::class);

        // drop untouched blank rows, but keep half-filled ones so they hit a real validation error
        $this->labors = array_values(array_filter(
            $this->labors,
            fn (array $row): bool => filled($row['employee_id']) || filled($row['planned_hours'])
        ));

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($this->labors === []) {
                $this->addLaborRow();
            }
            Flux::toast(variant: 'danger', text: __('Please fix the highlighted fields before submitting.'));
            throw $e;
        }

        $data = new CreateWorkOrderData(
            productionFormulaId: (int) $this->productionFormulaId,
            quantityTarget: $this->quantityTarget,
            plannedStartDate: $this->plannedStartDate,
            plannedEndDate: $this->plannedEndDate,
            createdBy: Auth::id(),
            labors: collect($this->labors)->map(fn (array $row) => [
                'employeeId' => (int) $row['employee_id'],
                'plannedHours' => (string) $row['planned_hours'],
            ])->all(),
            plannedMachineHours: (string) $this->plannedMachineHours,
        );

        try {
            $workOrder = $existing
                ? app(UpdateWorkOrderAction::class)->handle($existing, $data)
                : app(CreateWorkOrderAction::class)->handle($data);
        } catch (PlannedStartDateInPastException|InvalidPlannedDateRangeException|InactiveProductionFormulaException|WorkOrderRequiresLaborException|InactiveEmployeeException|MissingLaborRateException|MissingOverheadRateException|WorkOrderNotEditableException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $existing
            ? __('Work order :number updated.', ['number' => $workOrder->wo_number])
            : __('Work order :number created.', ['number' => $workOrder->wo_number]));

        $this->redirect(route('production.work-orders.show', $workOrder), navigate: true);
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
        <flux:breadcrumbs.item :href="route('production.dashboard')" wire:navigate>{{ __('Production') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('production.work-orders.list')" wire:navigate>{{ __('Work Orders') }}</flux:breadcrumbs.item>
        @if ($workOrderId)
            <flux:breadcrumbs.item :href="route('production.work-orders.show', $workOrderId)" wire:navigate>{{ $workOrderNumber }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Edit') }}</flux:breadcrumbs.item>
        @else
            <flux:breadcrumbs.item>{{ __('Create') }}</flux:breadcrumbs.item>
        @endif
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ $workOrderId ? __('Edit Work Order') : __('New Work Order') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                @if ($workOrderId)
                    <span class="font-data text-accent">{{ $workOrderNumber }}</span> · {{ __('Rates are refreshed when the work order is released.') }}
                @else
                    {{ __('Opened') }} {{ now()->format('d M Y') }}
                @endif
            </p>
        </div>

        <flux:button variant="primary" wire:click="create" wire:loading.attr="disabled" wire:target="create" class="active:scale-[0.95]">
            {{ __('Save Work Order') }}
        </flux:button>
    </div>

    <div class="mt-6 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 shadow-md shadow-zinc-900/4 dark:shadow-none p-6 sm:p-8 lg:p-10 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-x-10 gap-y-10">
            {{-- Main column --}}
            <div class="lg:col-span-2 space-y-10">
                {{-- Step 1: Formula & target --}}
                <section class="motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
                    <div class="flex items-center gap-2.5 pb-2.5 mb-5 border-b border-zinc-200 dark:border-white/10">
                        <span class="flex items-center justify-center size-5">
                            @if ($this->isFormulaStepComplete())
                                <span wire:key="step-1-done" wire:transition class="flex items-center justify-center size-5 rounded-sm bg-green-500/15 text-green-600 dark:text-green-400">
                                    <flux:icon.check class="size-3" />
                                </span>
                            @else
                                <span wire:key="step-1-pending" wire:transition class="flex items-center justify-center size-5 rounded-sm bg-accent/10 dark:bg-accent/20 font-data text-[10px] font-bold text-accent">1</span>
                            @endif
                        </span>
                        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">
                            {{ __('Formula & Target') }}
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-5 items-start gap-5">
                        <div class="md:col-span-3">
                            {{-- Custom listbox instead of a native <select>: a native option can't carry
                                 separate fonts/colours per part, so code, product, type and work center
                                 all collapsed into one grey line. Same pattern as the item-receipt PO picker. --}}
                            @php($selected = $this->selectedFormula)
                            {{-- flux:field + flux:label so the label gets exactly the same spacing as the
                                 neighbouring flux:input's label — a plain <label> sat ~2px off. --}}
                            <flux:field>
                            <flux:label id="formula-label">{{ __('Formula') }}</flux:label>
                            <div x-data="{ open: false }" x-on:keydown.escape="open = false; $refs.trigger.focus()" class="relative">
                                <button
                                    type="button"
                                    x-ref="trigger"
                                    x-on:click="open = !open"
                                    x-on:keydown.down.prevent="open = true; $nextTick(() => $focus.within($refs.panel).first())"
                                    aria-haspopup="listbox"
                                    aria-labelledby="formula-label"
                                    :aria-expanded="open"
                                    wire:loading.attr="disabled"
                                    wire:target="productionFormulaId"
                                    @class([
                                        // h-10 = the exact height of a default flux:input, so both controls in the row line up
                                        'w-full h-10 flex items-center justify-between gap-3 rounded-lg border bg-white dark:bg-white/5 px-3 text-left shadow-xs transition-colors cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/50 hover:border-zinc-300 dark:hover:border-white/20',
                                        'border-red-500' => $errors->has('productionFormulaId'),
                                        'border-zinc-200 dark:border-white/10' => !$errors->has('productionFormulaId'),
                                    ])
                                >
                                    @if ($selected)
                                        <span class="flex items-center gap-2 min-w-0">
                                            <span class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $selected->product->product_name }}</span>
                                            <span class="shrink-0 inline-flex items-center rounded-full border px-1.5 py-px text-[10px] font-medium {{ $this->productTypeChipClasses($selected->product->type) }}">{{ __($selected->product->type->label()) }}</span>
                                        </span>
                                    @else
                                        <span class="text-sm text-zinc-400 dark:text-zinc-500">{{ __('Select a production formula...') }}</span>
                                    @endif

                                    <span class="shrink-0 flex items-center">
                                        <flux:icon.loading wire:loading wire:target="productionFormulaId" variant="micro" class="size-4 text-accent" />
                                        <flux:icon.chevron-up-down wire:loading.remove wire:target="productionFormulaId" variant="micro" class="size-4 text-zinc-400" />
                                    </span>
                                </button>

                                <div
                                    x-ref="panel"
                                    x-show="open"
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    x-transition:leave="transition ease-in duration-100"
                                    x-transition:leave-start="opacity-100"
                                    x-transition:leave-end="opacity-0"
                                    x-on:click.outside="open = false"
                                    x-on:keydown.down.prevent="$focus.wrap().next()"
                                    x-on:keydown.up.prevent="$focus.wrap().previous()"
                                    x-cloak
                                    role="listbox"
                                    aria-labelledby="formula-label"
                                    class="absolute z-30 mt-1.5 w-full max-h-80 overflow-y-auto rounded-lg border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-1.5"
                                >
                                    @forelse ($this->formulasByWorkCenter as $workCenterName => $group)
                                        <p class="px-2.5 pt-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500" role="presentation">{{ __($workCenterName) }}</p>

                                        @foreach ($group as $formula)
                                            @php($isSelected = (string) $formula->id === $productionFormulaId)
                                            <button
                                                type="button"
                                                role="option"
                                                aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                                                wire:key="formula-option-{{ $formula->id }}"
                                                wire:click="$set('productionFormulaId', '{{ $formula->id }}')"
                                                x-on:click="open = false; $refs.trigger.focus()"
                                                @class([
                                                    'w-full flex items-center gap-3 rounded-md px-2.5 py-2 text-left cursor-pointer transition-colors focus:outline-none focus-visible:bg-accent/10 hover:bg-zinc-50 dark:hover:bg-white/5',
                                                    'bg-accent/5 dark:bg-accent/10' => $isSelected,
                                                ])
                                            >
                                                <span class="flex min-w-0 flex-1 flex-col">
                                                    <span class="flex items-center gap-2 min-w-0">
                                                        <span class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $formula->product->product_name }}</span>
                                                        <span class="shrink-0 inline-flex items-center rounded-full border px-1.5 py-px text-[10px] font-medium {{ $this->productTypeChipClasses($formula->product->type) }}">{{ __($formula->product->type->label()) }}</span>
                                                    </span>
                                                    <span class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">
                                                        <span class="text-accent">{{ $formula->formula_code }} v{{ $formula->version }}</span>
                                                        &middot; {{ trans_choice(':count material|:count materials', $formula->items_count) }}
                                                    </span>
                                                </span>
                                                @if ($isSelected)
                                                    <flux:icon.check variant="micro" class="size-4 shrink-0 text-accent" />
                                                @endif
                                            </button>
                                        @endforeach
                                    @empty
                                        <p class="px-3 py-4 text-center text-xs text-zinc-500 dark:text-zinc-400">{{ __('No active formulas yet.') }}</p>
                                    @endforelse
                                </div>
                            </div>

                            @error('productionFormulaId') <flux:error class="mt-1" :message="$message" /> @enderror
                            </flux:field>
                        </div>

                        <div class="md:col-span-2">
                            <flux:input.group :label="__('Target Quantity')">
                                <flux:input
                                    wire:model.live.debounce.400ms="quantityTarget"
                                    type="text"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    placeholder="0"
                                    input:class="text-right font-data tabular-nums"
                                    :loading="false"
                                />
                                <flux:input.group.suffix>{{ $this->selectedFormula?->product->unit_of_measure ?? __('Unit') }}</flux:input.group.suffix>
                            </flux:input.group>
                            @error('quantityTarget') <flux:error class="mt-1" :message="$message" /> @enderror
                        </div>
                    </div>
                </section>

                {{-- Step 2: Schedule --}}
                <section class="motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
                    <div class="flex items-center gap-2.5 pb-2.5 mb-5 border-b border-zinc-200 dark:border-white/10">
                        <span class="flex items-center justify-center size-5">
                            @if ($this->isScheduleStepComplete())
                                <span wire:key="step-2-done" wire:transition class="flex items-center justify-center size-5 rounded-sm bg-green-500/15 text-green-600 dark:text-green-400">
                                    <flux:icon.check class="size-3" />
                                </span>
                            @else
                                <span wire:key="step-2-pending" wire:transition class="flex items-center justify-center size-5 rounded-sm bg-accent/10 dark:bg-accent/20 font-data text-[10px] font-bold text-accent">2</span>
                            @endif
                        </span>
                        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">
                            {{ __('Schedule') }}
                        </h2>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 items-start gap-5">
                        <flux:input type="date" wire:model.live="plannedStartDate" :label="__('Planned Start')" :min="now()->toDateString()" />
                        <flux:input type="date" wire:model.live="plannedEndDate" :label="__('Planned End')" :min="$plannedStartDate" />
                    </div>
                </section>

                {{-- Step 3: Workers (<<include>> Mengalokasi Biaya Tenaga Kerja) --}}
                <section class="motion-safe:animate-fade-slide-up" style="animation-delay: 115ms;">
                    <div class="flex items-center justify-between pb-2.5 mb-5 border-b border-zinc-200 dark:border-white/10">
                        <div class="flex items-center gap-2.5">
                            <span class="flex items-center justify-center size-5">
                                @if ($this->isLaborStepComplete())
                                    <span wire:key="step-3-done" wire:transition class="flex items-center justify-center size-5 rounded-sm bg-green-500/15 text-green-600 dark:text-green-400">
                                        <flux:icon.check class="size-3" />
                                    </span>
                                @else
                                    <span wire:key="step-3-pending" wire:transition class="flex items-center justify-center size-5 rounded-sm bg-accent/10 dark:bg-accent/20 font-data text-[10px] font-bold text-accent">3</span>
                                @endif
                            </span>
                            <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">
                                {{ __('Workers') }}
                                <span class="text-zinc-400 dark:text-zinc-500 font-normal normal-case tracking-normal">
                                    ({{ str_pad((string) count($labors), 2, '0', STR_PAD_LEFT) }})
                                </span>
                            </h2>
                        </div>

                        <flux:button size="sm" variant="ghost" icon="plus" wire:click="addLaborRow" class="active:scale-[0.95]">
                            {{ __('Add Worker') }}
                        </flux:button>
                    </div>

                    @if ($this->employees->isEmpty())
                        <div class="flex flex-col items-center justify-center text-center py-8 rounded-md border border-dashed border-zinc-200 dark:border-white/10">
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No active employees yet. Management needs to add employees first.') }}</p>
                        </div>
                    @else
                        {{-- Desktop: a grid instead of a <table>, so the worker column absorbs
                             the leftover width (minmax(0,1fr)) and nothing forces horizontal scroll.
                             Rate rides under the cost as its explanation instead of taking a column. --}}
                        <div class="hidden sm:block rounded-md border border-zinc-200 dark:border-white/10" role="table" aria-label="{{ __('Workers') }}">
                            <div role="row" class="grid grid-cols-[minmax(0,1fr)_6.5rem_9rem_2rem] gap-x-3 px-3 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10 rounded-t-md">
                                <span role="columnheader">{{ __('Worker') }}</span>
                                <span role="columnheader" class="text-right">{{ __('Planned Hours') }}</span>
                                <span role="columnheader" class="text-right">{{ __('Cost') }}</span>
                                <span role="columnheader"><span class="sr-only">{{ __('Remove') }}</span></span>
                            </div>

                            @foreach ($labors as $index => $row)
                                @php($rate = $this->currentRateFor($row['employee_id']))
                                @php($workerName = $this->employees->firstWhere('id', (int) $row['employee_id'])?->name)
                                <div role="row" wire:key="labor-row-{{ $index }}" wire:transition
                                     x-data="{ focused: false }" x-on:focusin="focused = true" x-on:focusout="focused = false"
                                     :class="focused && 'bg-accent/5 dark:bg-accent/10'"
                                     class="grid grid-cols-[minmax(0,1fr)_6.5rem_9rem_2rem] gap-x-3 items-start px-3 py-2.5 border-b border-zinc-100 dark:border-white/5 last:border-0 transition-colors duration-150">
                                    <div role="cell" class="min-w-0">
                                        <x-picker :model="'labors.'.$index.'.employee_id'" :options="$this->workerOptions($index)" :selected="(string) $row['employee_id']" :placeholder="__('Select worker...')" :label="__('Worker')" :invalid="$errors->has('labors.'.$index.'.employee_id')" />
                                        @error("labors.$index.employee_id") <flux:error class="mt-1" :message="$message" /> @enderror
                                    </div>

                                    <div role="cell">
                                        <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*"
                                            input:class="text-right font-data tabular-nums"
                                            wire:model.live.debounce.400ms="labors.{{ $index }}.planned_hours"
                                            :loading="false" placeholder="0"
                                            :aria-label="$workerName ? __('Planned hours for :name', ['name' => $workerName]) : __('Planned Hours')" />
                                        @error("labors.$index.planned_hours") <flux:error class="mt-1" :message="$message" /> @enderror
                                    </div>

                                    <div role="cell" class="flex min-h-8 flex-col items-end justify-center text-right whitespace-nowrap">
                                        @if ($rate)
                                            <span class="font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($this->laborRowCost($row)) }}</span>
                                            <span class="font-data text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400">@ {{ $this->formatRupiah($rate) }} / {{ __('hrs') }}</span>
                                        @else
                                            <span class="text-zinc-400 dark:text-zinc-500">—</span>
                                        @endif
                                    </div>

                                    <div role="cell" class="flex justify-end">
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeLaborRow({{ $index }})"
                                            :aria-label="$workerName ? __('Remove :name', ['name' => $workerName]) : __('Remove')"
                                            class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 active:scale-[0.95]" />
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Mobile --}}
                        <div class="sm:hidden space-y-3">
                            @foreach ($labors as $index => $row)
                                @php($rate = $this->currentRateFor($row['employee_id']))
                                <div wire:key="labor-card-{{ $index }}" wire:transition class="rounded-md border border-zinc-200 dark:border-white/10 p-3.5">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex-1">
                                            <x-picker :model="'labors.'.$index.'.employee_id'" :options="$this->workerOptions($index)" :selected="(string) $row['employee_id']" :placeholder="__('Select worker...')" :label="__('Worker')" :invalid="$errors->has('labors.'.$index.'.employee_id')" />
                                            @error("labors.$index.employee_id") <flux:error class="mt-1" :message="$message" /> @enderror
                                        </div>
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeLaborRow({{ $index }})" :aria-label="__('Remove')" class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 active:scale-[0.95] shrink-0" />
                                    </div>
                                    <div class="grid grid-cols-2 items-start gap-3 mt-3">
                                        <div>
                                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" :label="__('Planned Hours')" input:class="text-right font-data tabular-nums" wire:model.live.debounce.400ms="labors.{{ $index }}.planned_hours" :loading="false" placeholder="0" />
                                            @error("labors.$index.planned_hours") <flux:error class="mt-1" :message="$message" /> @enderror
                                        </div>
                                        <div class="text-right text-xs">
                                            <p class="text-zinc-500 dark:text-zinc-400">{{ __('Rate / Hour') }}</p>
                                            <p class="mt-1.5 font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $rate ? $this->formatRupiah($rate) : '—' }}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between gap-2 mt-3 pt-3 border-t border-zinc-100 dark:border-white/10 text-xs">
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Cost') }}</span>
                                        <span class="font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($this->laborRowCost($row)) }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @error('labors') <flux:error class="mt-3" :message="$message" /> @enderror
                    @endif
                </section>

                {{-- Step 4: Machine hours → applied overhead (BOP dibebankan) --}}
                <section class="motion-safe:animate-fade-slide-up" style="animation-delay: 125ms;">
                    <div class="flex items-center gap-2.5 pb-2.5 mb-5 border-b border-zinc-200 dark:border-white/10">
                        <span class="flex items-center justify-center size-5">
                            @if ($this->isMachineStepComplete())
                                <span wire:key="step-4-done" wire:transition class="flex items-center justify-center size-5 rounded-sm bg-green-500/15 text-green-600 dark:text-green-400">
                                    <flux:icon.check class="size-3" />
                                </span>
                            @else
                                <span wire:key="step-4-pending" wire:transition class="flex items-center justify-center size-5 rounded-sm bg-accent/10 dark:bg-accent/20 font-data text-[10px] font-bold text-accent">4</span>
                            @endif
                        </span>
                        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">
                            {{ __('Machine & Overhead') }}
                        </h2>
                    </div>

                    @if (!$this->selectedFormula)
                        <div class="flex flex-col items-center justify-center text-center py-8 rounded-md border border-dashed border-zinc-200 dark:border-white/10">
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Select a formula first — it decides which work center runs this order.') }}</p>
                        </div>
                    @else
                        {{-- One panel that reads as the calculation it is: rate × machine hours = overhead.
                             Every cell shares the same eyebrow label + value rhythm, so nothing floats. --}}
                        @php($hasOverhead = bccomp($this->plannedOverheadCost, '0', 2) > 0)
                        <div class="rounded-md border border-zinc-200 dark:border-white/10 overflow-hidden grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_1.5rem_9rem_1.5rem_minmax(0,1fr)] divide-y sm:divide-y-0 divide-zinc-200 dark:divide-white/10">
                            {{-- Rate --}}
                            <div class="px-4 py-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Work Center') }}</p>
                                <p class="mt-1.5 text-sm font-medium text-zinc-900 dark:text-white">{{ __($this->selectedFormula->workCenter->name) }}</p>
                                @if ($this->overheadRate)
                                    <p class="font-data text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ $this->formatRupiah($this->overheadRate) }} / {{ __('machine hr') }}</p>
                                @else
                                    <p class="mt-0.5 inline-flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
                                        <flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" />
                                        {{ __('No overhead rate set') }}
                                    </p>
                                @endif
                            </div>

                            <div class="hidden sm:flex items-center justify-center font-data text-lg text-zinc-300 dark:text-zinc-600" aria-hidden="true">&times;</div>

                            {{-- Machine hours --}}
                            <div class="px-4 sm:px-0 py-3">
                                <label for="planned-machine-hours" class="block text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Machine Hours') }}</label>
                                <div class="mt-1">
                                    <flux:input id="planned-machine-hours" size="sm" wire:model.live.debounce.400ms="plannedMachineHours" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*"
                                        input:class="text-right font-data tabular-nums" placeholder="0" :loading="false" />
                                </div>
                                @error('plannedMachineHours') <flux:error class="mt-1" :message="$message" /> @enderror
                            </div>

                            <div class="hidden sm:flex items-center justify-center font-data text-lg text-zinc-300 dark:text-zinc-600" aria-hidden="true">=</div>

                            {{-- Result --}}
                            <div class="px-4 py-3 bg-zinc-50 dark:bg-white/[0.03] sm:text-right">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Planned Overhead') }}</p>
                                <p wire:key="oh-{{ $this->plannedOverheadCost }}" wire:transition.duration.200ms
                                   class="mt-1.5 font-data text-lg font-medium tabular-nums {{ $hasOverhead ? 'text-zinc-900 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">
                                    {{ $this->formatRupiah($this->plannedOverheadCost) }}
                                </p>
                            </div>
                        </div>
                    @endif
                </section>

                {{-- Material requirements preview --}}
                <section class="motion-safe:animate-fade-slide-up" style="animation-delay: 130ms;">
                    <div class="flex items-center justify-between gap-3 pb-2.5 mb-5 border-b border-zinc-200 dark:border-white/10">
                        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">
                            {{ __('Material Requirements') }}
                        </h2>
                        <span wire:loading wire:target="productionFormulaId,quantityTarget" class="text-accent">
                            <flux:icon.loading variant="micro" class="size-3.5" />
                        </span>
                    </div>

                    @if (!$this->selectedFormula)
                        <div class="flex flex-col items-center justify-center text-center py-10 rounded-md border border-dashed border-zinc-200 dark:border-white/10">
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Select a formula to see the materials it needs.') }}</p>
                        </div>
                    @else
                        <div wire:loading.class="opacity-50" wire:target="productionFormulaId,quantityTarget" class="transition-opacity">
                            {{-- Desktop --}}
                            <div class="hidden sm:block overflow-x-auto rounded-md border border-zinc-200 dark:border-white/10">
                                <table class="w-full text-xs border-collapse">
                                    <thead>
                                        <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                                            <th class="py-2 px-3">{{ __('Material') }}</th>
                                            <th class="py-2 px-3 text-right">{{ __('Per Batch') }}</th>
                                            <th class="py-2 px-3 text-right">{{ __('Required') }}</th>
                                            <th class="py-2 px-3 text-right">{{ __('In Stock') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($this->materialRequirements as $row)
                                            <tr wire:key="req-{{ $row['product']->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                                                <td class="py-2 px-3">
                                                    <p class="font-data text-accent">{{ $row['product']->product_code }}</p>
                                                    <p class="text-zinc-700 dark:text-zinc-300">{{ $row['product']->product_name }}</p>
                                                </td>
                                                <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                                    {{ $this->formatQuantity($row['per_batch']) }} {{ $row['product']->unit_of_measure }}
                                                </td>
                                                <td class="py-2 px-3 text-right font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">
                                                    {{ $this->formatQuantity($row['required']) }} {{ $row['product']->unit_of_measure }}
                                                </td>
                                                <td class="py-2 px-3 text-right whitespace-nowrap">
                                                    @if ($row['is_short'])
                                                        <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 font-data tabular-nums text-red-700 dark:text-red-400">
                                                            <flux:icon.exclamation-triangle variant="micro" class="size-3" />
                                                            {{ $this->formatQuantity((string) $row['product']->current_stock) }}
                                                        </span>
                                                    @else
                                                        <span class="font-data tabular-nums text-zinc-600 dark:text-zinc-400">{{ $this->formatQuantity((string) $row['product']->current_stock) }}</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            {{-- Mobile --}}
                            <div class="sm:hidden space-y-2">
                                @foreach ($this->materialRequirements as $row)
                                    <div wire:key="req-m-{{ $row['product']->id }}" class="rounded-md border border-zinc-200 dark:border-white/10 p-3 text-xs">
                                        <div class="flex items-start justify-between gap-2">
                                            <div>
                                                <p class="font-data text-accent">{{ $row['product']->product_code }}</p>
                                                <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $row['product']->product_name }}</p>
                                            </div>
                                            <p class="font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatQuantity($row['required']) }} {{ $row['product']->unit_of_measure }}</p>
                                        </div>
                                        <div class="mt-2 flex items-center justify-between gap-2 text-zinc-500 dark:text-zinc-400">
                                            <span>{{ __('In Stock') }}</span>
                                            @if ($row['is_short'])
                                                <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 font-data tabular-nums text-red-700 dark:text-red-400">
                                                    <flux:icon.exclamation-triangle variant="micro" class="size-3" />
                                                    {{ $this->formatQuantity((string) $row['product']->current_stock) }}
                                                </span>
                                            @else
                                                <span class="font-data tabular-nums">{{ $this->formatQuantity((string) $row['product']->current_stock) }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            @if ($this->shortMaterialCount > 0)
                                <p wire:key="short-notice" wire:transition class="mt-3 flex items-start gap-1.5 text-xs text-red-600 dark:text-red-400">
                                    <flux:icon.exclamation-triangle variant="micro" class="size-3.5 mt-px shrink-0" />
                                    {{ trans_choice(':count material is short for this target. You can still save the work order; stock is only consumed when materials are issued.|:count materials are short for this target. You can still save the work order; stock is only consumed when materials are issued.', $this->shortMaterialCount) }}
                                </p>
                            @endif
                        </div>
                    @endif
                </section>
            </div>

            {{-- Summary rail --}}
            <div class="lg:col-span-1">
                <div class="lg:sticky lg:top-6 motion-safe:animate-fade-slide-up" style="animation-delay: 160ms;">
                    <div class="rounded-md border border-zinc-200 dark:border-white/10 overflow-hidden">
                        <div class="px-4 py-3 border-b border-zinc-200 dark:border-white/10">
                            <h2 class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Work Order Summary') }}</h2>
                        </div>

                        <dl class="divide-y divide-zinc-100 dark:divide-white/5 text-sm">
                            <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Product') }}</dt>
                                <dd class="text-right text-zinc-800 dark:text-zinc-200">{{ $this->selectedFormula?->product->product_name ?? '—' }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Materials') }}</dt>
                                <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ str_pad((string) $this->materialRequirements->count(), 2, '0', STR_PAD_LEFT) }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Duration') }}</dt>
                                <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">
                                    @if ($this->isScheduleStepComplete() && $plannedEndDate >= $plannedStartDate)
                                        {{ \Illuminate\Support\Carbon::parse($plannedStartDate)->diffInDays(\Illuminate\Support\Carbon::parse($plannedEndDate)) + 1 }} {{ __('days') }}
                                    @else
                                        —
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        <div class="px-4 py-4 bg-accent">
                            <p class="text-[10px] font-semibold uppercase tracking-widest text-accent-foreground/70">{{ __('Target Output') }}</p>
                            <p wire:key="target-{{ $quantityTarget }}" wire:transition.duration.300ms class="font-data text-xl font-medium tabular-nums text-accent-foreground mt-0.5">
                                {{ $this->hasValidTarget() ? $quantityTarget : '0' }} {{ $this->selectedFormula?->product->unit_of_measure }}
                            </p>
                        </div>
                    </div>

                    <p class="text-xs text-zinc-500 dark:text-zinc-400 px-1 mt-3">
                        {{ __('The formula is a starting template. Actual materials and quantities are recorded when materials are issued to this work order.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
