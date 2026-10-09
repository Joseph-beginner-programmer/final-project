<?php

use App\Actions\Production\SaveProductionResultDraftAction;
use App\DTO\Production\ProductionResultData;
use App\Exceptions\InactiveEmployeeException;
use App\Exceptions\InvalidProductionResultException;
use App\Exceptions\MissingLaborRateException;
use App\Exceptions\ProductionResultNotEditableException;
use App\Exceptions\WorkOrderNotRecordableException;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductionResult;
use App\Models\WorkOrder;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Production Result')] class extends Component {
    #[Locked]
    public int $workOrderId;

    #[Locked]
    public ?int $productionResultId = null;

    public string $productionDate = '';

    public string $quantityGood = '';

    public string $quantityReject = '';

    public string $rejectReason = '';

    public string $machineHours = '';

    public string $notes = '';

    /** material used per product: product_id => ['quantity' => string] */
    public array $used = [];

    /** actual hours of the WO's planned workers: employee_id => ['hours' => string] */
    public array $hours = [];

    /** workers added on the day (not in the plan): list of ['employee_id', 'hours'] */
    public array $extras = [];

    public function mount(?WorkOrder $workOrder = null, ?ProductionResult $productionResult = null): void
    {
        if ($productionResult?->exists) {
            Gate::authorize('update', $productionResult);
            $workOrder = $productionResult->workOrder;
            $this->productionResultId = $productionResult->id;
        } else {
            Gate::authorize('create', ProductionResult::class);

            // one result per WO — an existing one is opened instead of starting a second
            if ($existing = $workOrder->productionResult) {
                $this->redirectRoute('production.results.show', $existing, navigate: true);

                return;
            }
        }

        $this->workOrderId = $workOrder->id;

        if (! $workOrder->canRecordResult()) {
            Flux::toast(variant: 'danger', text: (new WorkOrderNotRecordableException($workOrder, WorkOrderNotRecordableException::NOT_IN_PROGRESS))->userMessage());
            $this->redirectRoute('production.results.list', navigate: true);

            return;
        }

        $issued = $workOrder->issuedQuantities();

        if ($productionResult?->exists) {
            $this->productionDate = $productionResult->production_date->toDateString();
            $this->quantityGood = $this->formatQuantity((string) $productionResult->quantity_good);
            $this->quantityReject = $this->formatQuantity((string) $productionResult->quantity_reject);
            $this->rejectReason = (string) $productionResult->reject_reason;
            $this->machineHours = $this->formatQuantity((string) $productionResult->machine_hours);
            $this->notes = (string) $productionResult->notes;

            $usedSoFar = $productionResult->usedQuantities();
            foreach ($issued as $productId => $quantity) {
                $this->used[$productId] = ['quantity' => $this->formatQuantity($usedSoFar[$productId] ?? '0')];
            }

            $actual = $productionResult->labors->pluck('hours', 'employee_id');
            foreach ($workOrder->labors as $labor) {
                $this->hours[$labor->employee_id] = ['hours' => $this->formatQuantity((string) ($actual[$labor->employee_id] ?? '0'))];
            }
            foreach ($actual as $employeeId => $value) {
                if (! array_key_exists($employeeId, $this->hours)) {
                    $this->extras[] = ['employee_id' => (string) $employeeId, 'hours' => $this->formatQuantity((string) $value)];
                }
            }
        } else {
            $this->productionDate = today()->toDateString();

            // pre-filled with everything issued; lowered when there is a leftover (decided 2026-10-09)
            foreach ($issued as $productId => $quantity) {
                $this->used[$productId] = ['quantity' => $this->formatQuantity($quantity)];
            }

            foreach ($workOrder->labors as $labor) {
                $this->hours[$labor->employee_id] = ['hours' => $this->formatQuantity((string) $labor->planned_hours)];
            }
        }
    }

    #[Computed]
    public function workOrder(): WorkOrder
    {
        return WorkOrder::with(['product', 'workCenter', 'materials.product', 'labors.employee'])->findOrFail($this->workOrderId);
    }

    /**
     * One row per material: everything planned, plus anything issued outside the plan.
     */
    #[Computed]
    public function materialRows(): Collection
    {
        $issued = $this->workOrder->issuedQuantities();
        $planned = $this->workOrder->materials->keyBy('product_id');
        $productIds = $planned->keys()->merge(array_keys($issued))->unique();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        return $productIds->map(function ($productId) use ($issued, $planned, $products) {
            $issuedQty = $issued[$productId] ?? '0';
            $usedQty = $this->used[$productId]['quantity'] ?? '0';
            $leftover = is_numeric($usedQty) ? bcsub($issuedQty, (string) $usedQty, 2) : $issuedQty;

            return [
                'product' => $products[$productId],
                'planned' => $planned->has($productId) ? (string) $planned[$productId]->quantity_planned : null,
                'issued' => $issuedQty,
                'leftover' => $leftover,
                'over' => bccomp($leftover, '0', 2) < 0,
            ];
        })->values();
    }

    #[Computed]
    public function activeEmployees(): Collection
    {
        return Employee::active()->orderBy('name')->get();
    }

    public function extraOptions(int $index): array
    {
        $plannedIds = array_map('intval', array_keys($this->hours));
        $taken = collect($this->extras)->except($index)->pluck('employee_id')->filter()->map(fn ($id) => (int) $id)->all();
        $date = $this->completionDate();

        return $this->activeEmployees
            ->reject(fn (Employee $employee) => in_array($employee->id, $plannedIds, true))
            ->map(function (Employee $employee) use ($taken, $date) {
                $rate = $employee->laborRateOn($date)?->hourly_rate;

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
            })->values()->all();
    }

    public function addExtra(): void
    {
        $this->extras[] = ['employee_id' => '', 'hours' => ''];
    }

    public function removeExtra(int $index): void
    {
        unset($this->extras[$index]);
        $this->extras = array_values($this->extras);
    }

    protected function completionDate(): Carbon
    {
        try {
            return Carbon::parse($this->productionDate ?: today());
        } catch (\Throwable) {
            return today();
        }
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
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    protected function rules(): array
    {
        return [
            'productionDate' => ['required', 'date', 'before_or_equal:today'],
            'quantityGood' => ['required', 'numeric', 'gt:0'],
            'quantityReject' => ['nullable', 'numeric', 'min:0'],
            'rejectReason' => ['nullable', 'string', 'max:255'],
            'machineHours' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'used.*.quantity' => ['required', 'numeric', 'min:0'],
            'hours.*.hours' => ['nullable', 'numeric', 'min:0'],
            'extras.*.employee_id' => ['required', 'distinct', 'exists:employees,id'],
            'extras.*.hours' => ['required', 'numeric', 'gt:0'],
        ];
    }

    protected function messages(): array
    {
        return [
            'productionDate.before_or_equal' => __('The completion date cannot be in the future.'),
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'productionDate' => __('Completion date'),
            'quantityGood' => __('Good'),
            'quantityReject' => __('Reject'),
            'rejectReason' => __('Reject reason'),
            'machineHours' => __('Machine Hours'),
            'notes' => __('Notes'),
            'used.*.quantity' => __('Used'),
            'hours.*.hours' => __('Actual hours'),
            'extras.*.employee_id' => __('Worker'),
            'extras.*.hours' => __('Actual hours'),
        ];
    }

    public function save(): void
    {
        $result = $this->productionResultId ? ProductionResult::findOrFail($this->productionResultId) : null;
        $result ? Gate::authorize('update', $result) : Gate::authorize('create', ProductionResult::class);

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Flux::toast(variant: 'danger', text: __('Please fix the highlighted fields before submitting.'));
            throw $e;
        }

        $laborHours = [];
        foreach ($this->hours as $employeeId => $row) {
            $laborHours[(int) $employeeId] = (string) ($row['hours'] ?? '');
        }
        foreach ($this->extras as $row) {
            $laborHours[(int) $row['employee_id']] = (string) $row['hours'];
        }

        $data = new ProductionResultData(
            productionDate: $this->productionDate,
            quantityGood: $this->quantityGood,
            quantityReject: $this->quantityReject,
            machineHours: $this->machineHours,
            materialsUsed: collect($this->used)->mapWithKeys(fn ($row, $productId) => [(int) $productId => (string) $row['quantity']])->all(),
            laborHours: $laborHours,
            rejectReason: $this->rejectReason,
            notes: $this->notes,
        );

        try {
            $result = app(SaveProductionResultDraftAction::class)->handle($this->workOrder, $data, Auth::id(), $result);
        } catch (InvalidProductionResultException|WorkOrderNotRecordableException|ProductionResultNotEditableException|InactiveEmployeeException|MissingLaborRateException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Draft :number saved. Costs are calculated when it is posted.', ['number' => $result->result_number]));
        $this->redirectRoute('production.results.show', $result, navigate: true);
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

@php($wo = $this->workOrder)
@php($unit = $wo->product->unit_of_measure)
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('production.dashboard')" wire:navigate>{{ __('Production') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('production.results.list')" wire:navigate>{{ __('Production Results') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $productionResultId ? __('Edit') : __('Create') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ $productionResultId ? __('Edit Production Result') : __('Record Production Result') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                {{ __('For') }} <a href="{{ route('production.work-orders.show', $wo) }}" wire:navigate class="font-data text-accent hover:underline">{{ $wo->wo_number }}</a> · {{ $wo->product->product_name }}
                · {{ __('Work Center') }} {{ __($wo->workCenter->name) }}
            </p>
        </div>

        <flux:button variant="primary" icon="document-check" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">
            {{ __('Save Draft') }}
        </flux:button>
    </div>

    {{-- Output & machine --}}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4 items-start motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="lg:col-span-2 rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Output') }}</h2>
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-4 items-start">
                <div>
                    <flux:input type="date" wire:model="productionDate" :label="__('Completion date')" :max="today()->toDateString()" />
                </div>
                <div>
                    <flux:field>
                        <flux:label>{{ __('Good') }}</flux:label>
                        <flux:input.group>
                            <flux:input type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false" wire:model.live.debounce.400ms="quantityGood" />
                            <flux:input.group.suffix class="px-2 text-xs">{{ $unit }}</flux:input.group.suffix>
                        </flux:input.group>
                        <flux:error name="quantityGood" />
                    </flux:field>
                    <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Planned') }} <span class="font-data tabular-nums">{{ $this->formatQuantity((string) $wo->quantity_target) }} {{ $unit }}</span></p>
                </div>
                <div>
                    <flux:field>
                        <flux:label>{{ __('Reject') }}</flux:label>
                        <flux:input.group>
                            <flux:input type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false" wire:model.live.debounce.400ms="quantityReject" />
                            <flux:input.group.suffix class="px-2 text-xs">{{ $unit }}</flux:input.group.suffix>
                        </flux:input.group>
                        <flux:error name="quantityReject" />
                    </flux:field>
                </div>
            </div>

            @if (is_numeric($quantityReject) && (float) $quantityReject > 0)
                <div class="mt-4">
                    <flux:input wire:model="rejectReason" :label="__('Reject reason')" :placeholder="__('e.g. cracked, short shot')" />
                </div>
            @endif

            <div class="mt-4">
                <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" :placeholder="__('Anything from the paper log worth keeping')" />
            </div>
        </div>

        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Machine & Overhead') }}</h2>
            <div class="mt-3">
                <flux:field>
                    <flux:label>{{ __('Machine Hours') }}</flux:label>
                    <flux:input.group>
                        <flux:input type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false" wire:model.live.debounce.400ms="machineHours" />
                        <flux:input.group.suffix class="px-2 text-xs">{{ __('hrs') }}</flux:input.group.suffix>
                    </flux:input.group>
                    <flux:error name="machineHours" />
                </flux:field>
                <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Planned') }} <span class="font-data tabular-nums">{{ $this->formatQuantity((string) $wo->planned_machine_hours) }} {{ __('hrs') }}</span> · {{ $this->formatRupiah((string) $wo->overhead_rate) }}/{{ __('hrs') }}</p>
            </div>
        </div>
    </div>

    {{-- Workers --}}
    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Workers') }}</h2>
            <flux:button size="sm" variant="ghost" icon="plus" wire:click="addExtra" class="active:scale-[0.95]">{{ __('Add worker') }}</flux:button>
        </div>

        @php($workerGrid = 'grid grid-cols-[minmax(0,1fr)_6rem_8rem] md:grid-cols-[minmax(0,1fr)_7rem_9rem_2rem] gap-x-3 gap-y-2 items-start')
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <div class="{{ $workerGrid }} px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10 rounded-t-xl">
                <span>{{ __('Worker') }}</span>
                <span class="text-right">{{ __('Planned') }}</span>
                <span class="text-right">{{ __('Actual hours') }}</span>
                <span class="hidden md:block"></span>
            </div>

            @foreach ($wo->labors as $labor)
                <div wire:key="worker-{{ $labor->employee_id }}" class="{{ $workerGrid }} px-4 py-3 border-b border-zinc-100 dark:border-white/5">
                    <div class="min-w-0">
                        <p class="text-sm text-zinc-900 dark:text-white">{{ $labor->employee->name }}</p>
                        <p class="font-data text-[11px] text-accent">{{ $labor->employee->employee_code }}</p>
                    </div>
                    <p class="pt-2 text-right font-data text-xs tabular-nums text-zinc-600 dark:text-zinc-400">{{ $this->formatQuantity((string) $labor->planned_hours) }} {{ __('hrs') }}</p>
                    <div>
                        <flux:input.group>
                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false"
                                wire:model="hours.{{ $labor->employee_id }}.hours" :aria-label="__('Actual hours').' — '.$labor->employee->name" />
                            <flux:input.group.suffix class="px-2 text-xs">{{ __('hrs') }}</flux:input.group.suffix>
                        </flux:input.group>
                        @error("hours.{$labor->employee_id}.hours") <flux:error class="mt-1" :message="$message" /> @enderror
                    </div>
                    <span class="hidden md:block"></span>
                </div>
            @endforeach

            @foreach ($extras as $index => $extra)
                <div wire:key="extra-worker-{{ $index }}" class="{{ $workerGrid }} px-4 py-3 border-b border-zinc-100 dark:border-white/5 bg-amber-50/40 dark:bg-amber-500/[0.04]">
                    <div class="min-w-0">
                        <x-picker :model="'extras.'.$index.'.employee_id'" :options="$this->extraOptions($index)" :selected="(string) $extra['employee_id']"
                            size="sm" :placeholder="__('Select worker...')" :label="__('Worker')" :invalid="$errors->has('extras.'.$index.'.employee_id')" />
                        @error("extras.$index.employee_id") <flux:error class="mt-1" :message="$message" /> @enderror
                    </div>
                    <p class="pt-2 text-right text-xs text-amber-700 dark:text-amber-400">{{ __('Not planned') }}</p>
                    <div>
                        <flux:input.group>
                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false"
                                wire:model="extras.{{ $index }}.hours" :aria-label="__('Actual hours')" />
                            <flux:input.group.suffix class="px-2 text-xs">{{ __('hrs') }}</flux:input.group.suffix>
                        </flux:input.group>
                        @error("extras.$index.hours") <flux:error class="mt-1" :message="$message" /> @enderror
                    </div>
                    <div class="col-span-3 md:col-span-1 flex justify-end">
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeExtra({{ $index }})" :aria-label="__('Remove')" class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 active:scale-[0.95]" />
                    </div>
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Enter 0 for a planned worker who did not work. Add a worker who replaced someone or helped out.') }}</p>
    </div>

    {{-- Materials --}}
    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">{{ __('Materials') }}</h2>

        @php($grid = 'grid grid-cols-3 md:grid-cols-[minmax(0,1fr)_6.5rem_6.5rem_9rem_6.5rem] gap-x-3 gap-y-2 items-start')
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <div class="{{ $grid }} hidden md:grid px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10 rounded-t-xl">
                <span>{{ __('Material') }}</span>
                <span class="text-right">{{ __('Planned') }}</span>
                <span class="text-right">{{ __('Issued') }}</span>
                <span class="text-right">{{ __('Used') }}</span>
                <span class="text-right">{{ __('Leftover') }}</span>
            </div>

            @forelse ($this->materialRows as $row)
                @php($p = $row['product'])
                <div wire:key="material-{{ $p->id }}" class="{{ $grid }} px-4 py-3 border-b border-zinc-100 dark:border-white/5 last:border-0">
                    <div class="col-span-3 md:col-span-1 min-w-0">
                        <p class="text-sm text-zinc-900 dark:text-white">
                            {{ $p->product_name }}
                            @if ($row['planned'] === null)
                                <span class="ml-1 inline-flex items-center rounded-full border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 px-1.5 py-px text-[10px] font-medium text-amber-700 dark:text-amber-400">{{ __('Outside plan') }}</span>
                            @endif
                        </p>
                        <p class="font-data text-[11px] text-accent">{{ $p->product_code }}</p>
                    </div>

                    @foreach ([__('Planned') => $row['planned'], __('Issued') => $row['issued']] as $label => $value)
                        <div class="md:text-right text-xs">
                            <p class="md:hidden text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                            <p class="md:pt-2 font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $value === null ? '—' : $this->formatQuantity($value).' '.$p->unit_of_measure }}</p>
                        </div>
                    @endforeach

                    <div class="col-span-3 md:col-span-1 md:col-start-4 md:row-start-1">
                        <p class="md:hidden mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Used') }}</p>
                        @if (array_key_exists($p->id, $used))
                            <flux:input.group>
                                <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false"
                                    wire:model.live.debounce.400ms="used.{{ $p->id }}.quantity" :aria-label="__('Used').' — '.$p->product_name" />
                                <flux:input.group.suffix class="px-2 text-xs">{{ $p->unit_of_measure }}</flux:input.group.suffix>
                            </flux:input.group>
                            @error("used.{$p->id}.quantity") <flux:error class="mt-1" :message="$message" /> @enderror
                        @else
                            <p class="md:pt-2 md:text-right text-xs text-zinc-500 dark:text-zinc-400">{{ __('Not issued') }}</p>
                        @endif
                    </div>

                    <div class="md:text-right text-xs md:col-start-5 md:row-start-1">
                        <p class="md:hidden text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Leftover') }}</p>
                        <p class="md:pt-2 font-data tabular-nums {{ $row['over'] ? 'text-red-600 dark:text-red-400' : (bccomp($row['leftover'], '0', 2) > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-zinc-500 dark:text-zinc-400') }}">
                            {{ $this->formatQuantity($row['leftover']) }} {{ $p->unit_of_measure }}
                        </p>
                    </div>
                </div>
            @empty
                <p class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No materials were issued to this work order.') }}</p>
            @endforelse
        </div>
        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Used starts at what the warehouse issued. Lower it when material is left over — the leftover goes back to the warehouse.') }}</p>
    </div>
</section>
