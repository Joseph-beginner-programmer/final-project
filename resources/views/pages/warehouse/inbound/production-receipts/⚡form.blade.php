<?php

use App\Actions\Warehouse\SaveProductionReceiptDraftAction;
use App\Exceptions\InvalidProductionReceiptException;
use App\Exceptions\ProductionReceiptNotEditableException;
use App\Models\ProductionReceipt;
use App\Models\ProductionResult;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Production Receipt')] class extends Component {
    #[Locked]
    public int $productionResultId;

    #[Locked]
    public ?int $productionReceiptId = null;

    public string $quantity = '';

    public string $notes = '';

    public function mount(?ProductionResult $productionResult = null, ?ProductionReceipt $productionReceipt = null): void
    {
        if ($productionReceipt?->exists) {
            Gate::authorize('update', $productionReceipt);
            $productionResult = $productionReceipt->productionResult;
            $this->productionReceiptId = $productionReceipt->id;
            $this->quantity = $this->formatQuantity((string) $productionReceipt->quantity);
            $this->notes = (string) $productionReceipt->notes;
        } else {
            Gate::authorize('create', ProductionReceipt::class);

            $draft = $productionResult->productionReceipts()->where('status', 'draft')->first();
            if ($draft) {
                $this->redirectRoute('warehouse.inbound.production-receipts.show', $draft, navigate: true);

                return;
            }

            $this->quantity = $this->formatQuantity((string) $productionResult->quantity_good);
        }

        $this->productionResultId = $productionResult->id;
    }

    #[Computed]
    public function result(): ProductionResult
    {
        return ProductionResult::with('workOrder.product')->findOrFail($this->productionResultId);
    }

    public function difference(): ?string
    {
        return is_numeric($this->quantity) ? bcsub((string) $this->quantity, (string) $this->result->quantity_good, 2) : null;
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
            'quantity' => ['required', 'numeric', 'gt:0', 'lte:'.(string) $this->result->quantity_good],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'quantity.lte' => __('More cannot be received than production reported as good (:quantity).', ['quantity' => $this->formatQuantity((string) $this->result->quantity_good)]),
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'quantity' => __('Received'),
            'notes' => __('Notes'),
        ];
    }

    public function save(): void
    {
        $receipt = $this->productionReceiptId ? ProductionReceipt::findOrFail($this->productionReceiptId) : null;
        $receipt ? Gate::authorize('update', $receipt) : Gate::authorize('create', ProductionReceipt::class);

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Flux::toast(variant: 'danger', text: __('Please fix the highlighted fields before submitting.'));
            throw $e;
        }

        try {
            $receipt = app(SaveProductionReceiptDraftAction::class)->handle($this->result, $this->quantity, $this->notes, Auth::id(), $receipt);
        } catch (InvalidProductionReceiptException|ProductionReceiptNotEditableException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Draft :number saved. Stock comes in only when it is posted.', ['number' => $receipt->receipt_number]));
        $this->redirectRoute('warehouse.inbound.production-receipts.show', $receipt, navigate: true);
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

@php($result = $this->result)
@php($product = $result->workOrder->product)
@php($diff = $this->difference())
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('warehouse.dashboard')" wire:navigate>{{ __('Warehouse') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('warehouse.inbound.production-receipts.list')" wire:navigate>{{ __('Production Receipt') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $productionReceiptId ? __('Edit') : __('Create') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ $productionReceiptId ? __('Edit Production Receipt') : __('Receive Production Output') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                {{ __('From') }} <span class="font-data text-accent">{{ $result->workOrder->wo_number }}</span>
                · {{ __('Production finished') }} <span class="font-data tabular-nums">{{ $result->production_date->format('d M Y') }}</span> · <span class="font-data">{{ $result->result_number }}</span>
            </p>
        </div>

        <flux:button variant="primary" icon="document-check" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">
            {{ __('Save Draft') }}
        </flux:button>
    </div>

    <div class="mt-6 max-w-3xl motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-5">
            <p class="font-data text-xs text-accent">{{ $product->product_code }}</p>
            <p class="font-display text-base font-bold text-zinc-900 dark:text-white">{{ $product->product_name }}</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $product->type->label() }}</p>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4 items-start">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Reported good') }}</p>
                    <p class="mt-2 font-data text-sm tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatQuantity((string) $result->quantity_good) }} {{ $product->unit_of_measure }}</p>
                </div>
                <div>
                    <flux:field>
                        <flux:label>{{ __('Received') }}</flux:label>
                        <flux:input.group>
                            <flux:input type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false" wire:model.live.debounce.400ms="quantity" />
                            <flux:input.group.suffix class="px-2 text-xs">{{ $product->unit_of_measure }}</flux:input.group.suffix>
                        </flux:input.group>
                        <flux:error name="quantity" />
                    </flux:field>
                </div>
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Difference') }}</p>
                    <p class="mt-2 font-data text-sm tabular-nums {{ $diff === null || bccomp($diff, '0', 2) === 0 ? 'text-zinc-500 dark:text-zinc-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $diff === null ? '—' : $this->formatQuantity($diff).' '.$product->unit_of_measure }}
                    </p>
                </div>
            </div>

            <div class="mt-4">
                <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" :placeholder="__('e.g. 5 units damaged during transport')" />
            </div>
        </div>
    </div>
</section>
