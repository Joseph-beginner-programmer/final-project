<?php

use App\Actions\Production\SaveMaterialIssueDraftAction;
use App\Enums\ProductType;
use App\Exceptions\InvalidMaterialIssueLineException;
use App\Exceptions\MaterialIssueNotEditableException;
use App\Exceptions\WorkOrderNotIssuableException;
use App\Models\MaterialIssue;
use App\Models\Product;
use App\Models\WorkOrder;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Material Issue')] class extends Component {
    #[Locked]
    public int $workOrderId;

    #[Locked]
    public ?int $materialIssueId = null;

    /** planned materials: product_id => ['quantity' => string] */
    public array $planned = [];

    /** materials outside the plan: list of ['product_id', 'quantity', 'note'] */
    public array $extras = [];

    public function mount(?WorkOrder $workOrder = null, ?MaterialIssue $materialIssue = null): void
    {
        if ($materialIssue?->exists) {
            Gate::authorize('update', $materialIssue);
            $workOrder = $materialIssue->workOrder;
            $this->materialIssueId = $materialIssue->id;
        } else {
            Gate::authorize('create', MaterialIssue::class);
        }

        $this->workOrderId = $workOrder->id;

        if (! $workOrder->canReceiveMaterials()) {
            Flux::toast(variant: 'danger', text: (new WorkOrderNotIssuableException($workOrder))->userMessage());
            $this->redirectRoute('warehouse.outbound.material-issues.list', navigate: true);

            return;
        }

        $issued = $workOrder->issuedQuantities();
        $draftLines = $materialIssue?->exists ? $materialIssue->items->keyBy('product_id') : collect();

        foreach ($workOrder->materials as $material) {
            if ($draftLines->isNotEmpty()) {
                $quantity = $draftLines->has($material->product_id) ? (string) $draftLines[$material->product_id]->quantity : '';
            } else {
                // a new issue starts from what's still needed: plan − already posted
                $remaining = bcsub((string) $material->quantity_planned, $issued[$material->product_id] ?? '0', 2);
                $quantity = bccomp($remaining, '0', 2) > 0 ? $remaining : '';
            }

            $this->planned[$material->product_id] = ['quantity' => $this->formatQuantity($quantity)];
        }

        foreach ($draftLines as $productId => $line) {
            if (! array_key_exists($productId, $this->planned)) {
                $this->extras[] = ['product_id' => (string) $productId, 'quantity' => $this->formatQuantity((string) $line->quantity), 'note' => (string) $line->note];
            }
        }
    }

    #[Computed]
    public function workOrder(): WorkOrder
    {
        return WorkOrder::with(['product', 'materials.product'])->findOrFail($this->workOrderId);
    }

    #[Computed]
    public function plannedRows(): Collection
    {
        $issued = $this->workOrder->issuedQuantities();

        return $this->workOrder->materials->map(function ($material) use ($issued) {
            $alreadyIssued = $issued[$material->product_id] ?? '0';
            $remaining = bcsub((string) $material->quantity_planned, $alreadyIssued, 2);
            $quantity = $this->planned[$material->product_id]['quantity'] ?? '';

            return [
                'product' => $material->product,
                'planned' => (string) $material->quantity_planned,
                'issued' => $alreadyIssued,
                'remaining' => bccomp($remaining, '0', 2) > 0 ? $remaining : '0',
                'stock' => (string) $material->product->current_stock,
                'is_short' => is_numeric($quantity) && bccomp((string) $quantity, (string) $material->product->current_stock, 2) > 0,
            ];
        });
    }

    /**
     * Raw materials and WIP outside the plan — already-added extras are shown as taken.
     */
    public function extraOptions(int $index): array
    {
        $plannedIds = array_map('intval', array_keys($this->planned));
        $taken = collect($this->extras)->except($index)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all();

        return Product::query()
            ->whereIn('type', [ProductType::RawMaterial->value, ProductType::Wip->value])
            ->whereNotIn('id', $plannedIds)
            ->orderBy('product_name')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'title' => $product->product_name,
                'subtitle' => $product->product_code,
                'meta' => __('Stock').' '.$this->formatQuantity((string) $product->current_stock).' '.$product->unit_of_measure,
                'disabled_reason' => in_array($product->id, $taken, true) ? __('Already chosen') : null,
            ])->all();
    }

    public function extraProduct(int $index): ?Product
    {
        $id = $this->extras[$index]['product_id'] ?? null;

        return $id ? Product::find($id) : null;
    }

    public function addExtra(): void
    {
        $this->extras[] = ['product_id' => '', 'quantity' => '', 'note' => ''];
    }

    public function removeExtra(int $index): void
    {
        unset($this->extras[$index]);
        $this->extras = array_values($this->extras);
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
            'planned.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'extras.*.product_id' => ['required', 'distinct', 'exists:products,id'],
            'extras.*.quantity' => ['required', 'numeric', 'gt:0'],
            'extras.*.note' => ['required', 'string', 'max:255'],
        ];
    }

    protected function messages(): array
    {
        return [
            'extras.*.note.required' => __('Explain why this material is needed outside the plan.'),
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'planned.*.quantity' => __('Quantity'),
            'extras.*.product_id' => __('Material'),
            'extras.*.quantity' => __('Quantity'),
            'extras.*.note' => __('Note'),
        ];
    }

    public function save(): void
    {
        $issue = $this->materialIssueId ? MaterialIssue::findOrFail($this->materialIssueId) : null;
        $issue ? Gate::authorize('update', $issue) : Gate::authorize('create', MaterialIssue::class);

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Flux::toast(variant: 'danger', text: __('Please fix the highlighted fields before submitting.'));
            throw $e;
        }

        $lines = [];
        foreach ($this->planned as $productId => $line) {
            $lines[] = ['productId' => (int) $productId, 'quantity' => (string) ($line['quantity'] ?? ''), 'note' => null];
        }
        foreach ($this->extras as $line) {
            $lines[] = ['productId' => (int) $line['product_id'], 'quantity' => (string) $line['quantity'], 'note' => $line['note']];
        }

        try {
            $issue = app(SaveMaterialIssueDraftAction::class)->handle($this->workOrder, $lines, Auth::id(), $issue);
        } catch (InvalidMaterialIssueLineException|WorkOrderNotIssuableException|MaterialIssueNotEditableException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Draft :number saved. Stock leaves only when it is posted.', ['number' => $issue->issue_number]));
        $this->redirectRoute('warehouse.outbound.material-issues.show', $issue, navigate: true);
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
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('warehouse.dashboard')" wire:navigate>{{ __('Warehouse') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('warehouse.outbound.material-issues.list')" wire:navigate>{{ __('Material Issue') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $materialIssueId ? __('Edit') : __('Create') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ $materialIssueId ? __('Edit Material Issue') : __('New Material Issue') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                {{ __('For') }} <span class="font-data text-accent">{{ $wo->wo_number }}</span> · {{ $wo->product->product_name }}
                · {{ __('Target Output') }} <span class="font-data tabular-nums">{{ $this->formatQuantity((string) $wo->quantity_target) }} {{ $wo->product->unit_of_measure }}</span>
            </p>
        </div>

        <flux:button variant="primary" icon="document-check" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">
            {{ __('Save Draft') }}
        </flux:button>
    </div>

    {{-- Planned materials --}}
    <div class="mt-6 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">{{ __('Planned Materials') }}</h2>

        @php($grid = 'grid grid-cols-2 md:grid-cols-[minmax(0,1fr)_6.5rem_6.5rem_6.5rem_7.5rem_10rem] gap-x-3 gap-y-2 items-start')
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <div class="{{ $grid }} hidden md:grid px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10 rounded-t-xl">
                <span>{{ __('Material') }}</span>
                <span class="text-right">{{ __('Planned') }}</span>
                <span class="text-right">{{ __('Already issued') }}</span>
                <span class="text-right">{{ __('Remaining') }}</span>
                <span class="text-right">{{ __('In Stock') }}</span>
                <span class="text-right">{{ __('Issue now') }}</span>
            </div>

            @forelse ($this->plannedRows as $row)
                @php($p = $row['product'])
                <div wire:key="planned-{{ $p->id }}" class="{{ $grid }} px-4 py-3 border-b border-zinc-100 dark:border-white/5 last:border-0">
                    <div class="col-span-2 md:col-span-1 min-w-0">
                        <p class="text-sm text-zinc-900 dark:text-white">{{ $p->product_name }}</p>
                        <p class="font-data text-[11px] text-accent">{{ $p->product_code }}</p>
                    </div>

                    @foreach ([__('Planned') => $row['planned'], __('Already issued') => $row['issued'], __('Remaining') => $row['remaining']] as $label => $value)
                        <div class="md:text-right text-xs">
                            <p class="md:hidden text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                            <p class="md:pt-2 font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity($value) }} {{ $p->unit_of_measure }}</p>
                        </div>
                    @endforeach

                    <div class="md:text-right text-xs">
                        <p class="md:hidden text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('In Stock') }}</p>
                        <div class="md:pt-1.5">
                            @if ($row['is_short'])
                                <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 font-data tabular-nums text-red-700 dark:text-red-400">
                                    <flux:icon.exclamation-triangle variant="micro" class="size-3" />
                                    {{ $this->formatQuantity($row['stock']) }} {{ $p->unit_of_measure }}
                                </span>
                            @else
                                <span class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity($row['stock']) }} {{ $p->unit_of_measure }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="col-span-2 md:col-span-1">
                        <p class="md:hidden mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Issue now') }}</p>
                        <flux:input.group>
                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0"
                                input:class="text-right font-data tabular-nums" :loading="false"
                                wire:model.live.debounce.400ms="planned.{{ $p->id }}.quantity"
                                :aria-label="__('Issue now').' — '.$p->product_name" />
                            <flux:input.group.suffix class="px-2 text-xs">{{ $p->unit_of_measure }}</flux:input.group.suffix>
                        </flux:input.group>
                        @error("planned.{$p->id}.quantity") <flux:error class="mt-1" :message="$message" /> @enderror
                    </div>
                </div>
            @empty
                <p class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('This work order has no planned materials.') }}</p>
            @endforelse
        </div>
        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Leave a material empty to skip it in this issue. Issuing more than planned is allowed.') }}</p>
    </div>

    {{-- Materials outside the plan --}}
    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Materials outside the plan') }}</h2>
            <flux:button size="sm" variant="ghost" icon="plus" wire:click="addExtra" class="active:scale-[0.95]">{{ __('Add material') }}</flux:button>
        </div>

        @if ($extras === [])
            <div class="rounded-md border border-dashed border-zinc-200 dark:border-white/10 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('None — add one if production needs extra material (e.g. to compensate damaged goods).') }}
            </div>
        @else
            <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5">
                @foreach ($extras as $index => $extra)
                    @php($extraProduct = $this->extraProduct($index))
                    <div wire:key="extra-{{ $index }}" class="grid grid-cols-[minmax(0,1fr)_2rem] md:grid-cols-[minmax(0,1fr)_10rem_minmax(0,1fr)_2rem] gap-x-3 gap-y-2 items-start px-4 py-3">
                        <div class="min-w-0">
                            <x-picker :model="'extras.'.$index.'.product_id'" :options="$this->extraOptions($index)" :selected="(string) $extra['product_id']"
                                :placeholder="__('Select material...')" :label="__('Material')" :invalid="$errors->has('extras.'.$index.'.product_id')" />
                            @error("extras.$index.product_id") <flux:error class="mt-1" :message="$message" /> @enderror
                        </div>
                        <div class="col-start-2 row-start-1 md:col-start-4 flex justify-end">
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeExtra({{ $index }})" :aria-label="__('Remove')" class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 active:scale-[0.95]" />
                        </div>
                        <div class="col-span-2 md:col-span-1 md:col-start-2 md:row-start-1">
                            <flux:input.group>
                                <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0"
                                    input:class="text-right font-data tabular-nums" :loading="false"
                                    wire:model.live.debounce.400ms="extras.{{ $index }}.quantity" :aria-label="__('Quantity')" />
                                <flux:input.group.suffix class="px-2 text-xs">{{ $extraProduct?->unit_of_measure ?? '—' }}</flux:input.group.suffix>
                            </flux:input.group>
                            @error("extras.$index.quantity") <flux:error class="mt-1" :message="$message" /> @enderror
                        </div>
                        <div class="col-span-2 md:col-span-1 md:col-start-3 md:row-start-1">
                            <flux:input size="sm" wire:model="extras.{{ $index }}.note" :placeholder="__('Why is it needed?')" :aria-label="__('Note')" />
                            @error("extras.$index.note") <flux:error class="mt-1" :message="$message" /> @enderror
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
