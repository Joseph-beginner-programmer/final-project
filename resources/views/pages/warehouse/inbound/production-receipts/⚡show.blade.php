<?php

use App\Actions\Warehouse\CancelProductionReceiptAction;
use App\Actions\Warehouse\PostProductionReceiptAction;
use App\Enums\DocumentStatus;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use App\Exceptions\InvalidProductionReceiptException;
use App\Exceptions\ProductionReceiptNotEditableException;
use App\Models\ProductionReceipt;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Production Receipt')] class extends Component {
    public ProductionReceipt $productionReceipt;

    public function mount(): void
    {
        Gate::authorize('view', $this->productionReceipt);
        $this->loadRelations();
    }

    protected function loadRelations(): void
    {
        $this->productionReceipt->load(['product', 'productionResult.workOrder', 'lot', 'createdBy', 'receivedBy']);
    }

    public function journalPlan(): ?array
    {
        if ($this->productionReceipt->status->value !== 'draft') {
            return null;
        }

        try {
            return app(\App\Actions\Warehouse\PostProductionReceiptAction::class)->preview($this->productionReceipt);
        } catch (\Throwable) {
            return null;
        }
    }

    public function post(): void
    {
        Gate::authorize('post', $this->productionReceipt);

        try {
            app(PostProductionReceiptAction::class)->handle($this->productionReceipt, Auth::id());
        } catch (InvalidProductionReceiptException|ProductionReceiptNotEditableException|InvalidDocumentStatusTransitionException $e) {
            $this->modal('post-receipt')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->productionReceipt->refresh();
        $this->loadRelations();
        $this->modal('post-receipt')->close();
        Flux::toast(variant: 'success', text: __('Posted — the output is in stock as lot :lot.', ['lot' => $this->productionReceipt->lot->lot_number]));
    }

    public function cancel(): void
    {
        Gate::authorize('cancel', $this->productionReceipt);

        try {
            app(CancelProductionReceiptAction::class)->handle($this->productionReceipt);
        } catch (ProductionReceiptNotEditableException|InvalidDocumentStatusTransitionException $e) {
            $this->modal('cancel-receipt')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->productionReceipt->refresh();
        $this->modal('cancel-receipt')->close();
        Flux::toast(variant: 'success', text: __('Production receipt cancelled.'));
    }

    public function formatQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    public function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    public function documentBadgeClasses(DocumentStatus $status): string
    {
        return match ($status) {
            DocumentStatus::Draft => 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:border-white/10',
            DocumentStatus::Posted => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
            DocumentStatus::Cancelled => 'bg-zinc-100 text-zinc-500 border-zinc-200 line-through dark:bg-white/5 dark:text-zinc-500 dark:border-white/10',
        };
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

@php($receipt = $productionReceipt)
@php($result = $receipt->productionResult)
@php($unit = $receipt->product->unit_of_measure)
@php($posted = $receipt->status === DocumentStatus::Posted)
@php($showCost = auth()->user()->can('costs.view'))
@php($shortage = $receipt->shortageQuantity())
@php($label = 'text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('warehouse.inbound.production-receipts.list', ['tab' => 'documents'])" wire:navigate>{{ __('Production Receipt') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $receipt->receipt_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-data text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $receipt->receipt_number }}</h1>
                <span wire:key="status-{{ $receipt->status->value }}" wire:transition class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $this->documentBadgeClasses($receipt->status) }}">{{ $receipt->status->label() }}</span>
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                {{ __('From') }} <span class="font-data text-accent">{{ $result->workOrder->wo_number }}</span> · <span class="font-data">{{ $result->result_number }}</span>
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" :href="route('warehouse.inbound.production-receipts.list', ['tab' => 'documents'])" wire:navigate class="active:scale-[0.95]">{{ __('Back to List') }}</flux:button>
            @can('update', $receipt)
                <flux:button variant="filled" icon="pencil-square" :href="route('warehouse.inbound.production-receipts.edit', $receipt)" wire:navigate class="active:scale-[0.95]">{{ __('Edit') }}</flux:button>
            @endcan
            @can('cancel', $receipt)
                <flux:modal.trigger name="cancel-receipt">
                    <flux:button variant="ghost" icon="x-mark" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button>
                </flux:modal.trigger>
            @endcan
            @can('post', $receipt)
                <flux:modal.trigger name="post-receipt">
                    <flux:button variant="primary" icon="inbox-arrow-down" class="active:scale-[0.95]">{{ __('Post') }}</flux:button>
                </flux:modal.trigger>
            @endcan
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="lg:col-span-2 rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-5">
            <p class="font-data text-xs text-accent">{{ $receipt->product->product_code }}</p>
            <p class="font-display text-base font-bold text-zinc-900 dark:text-white">{{ $receipt->product->product_name }}</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $receipt->product->type->label() }}</p>

            <div class="mt-4 grid grid-cols-3 gap-4">
                <div>
                    <p class="{{ $label }}">{{ __('Reported good') }}</p>
                    <p class="mt-1 font-data text-sm tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity((string) $receipt->quantity_expected) }} {{ $unit }}</p>
                </div>
                <div>
                    <p class="{{ $label }}">{{ __('Received') }}</p>
                    <p class="mt-1 font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $receipt->quantity) }} {{ $unit }}</p>
                </div>
                <div>
                    <p class="{{ $label }}">{{ __('Shortage') }}</p>
                    <p class="mt-1 font-data text-sm tabular-nums {{ bccomp($shortage, '0', 2) > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $this->formatQuantity($shortage) }} {{ $unit }}</p>
                </div>
            </div>
            @if ($receipt->notes)
                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Notes') }}: “{{ $receipt->notes }}”</p>
            @endif
        </div>

        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 overflow-hidden flex flex-col">
            <div class="p-5 flex-1">
                <p class="{{ $label }}">{{ __('Lot created') }}</p>
                @if ($posted && $receipt->lot)
                    <p class="mt-1 font-data text-base font-medium text-zinc-900 dark:text-white">{{ $receipt->lot->lot_number }}</p>
                    <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $receipt->receivedBy?->name }} · {{ $receipt->received_at?->format('d M Y H:i') }}</p>
                @else
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Created on posting') }}</p>
                @endif
            </div>
            @if ($posted && $showCost)
                <dl class="border-t border-zinc-100 dark:border-white/10 px-5 py-3 space-y-1.5 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Unit cost') }}</dt>
                        <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatRupiah((string) $receipt->unit_cost) }}/{{ $unit }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Lot value') }}</dt>
                        <dd class="font-data tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $receipt->total_value) }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Shortage (loss)') }}</dt>
                        <dd class="font-data tabular-nums {{ bccomp((string) $receipt->shortage_value, '0', 2) > 0 ? 'text-red-600 dark:text-red-400' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $this->formatRupiah((string) $receipt->shortage_value) }}</dd>
                    </div>
                </dl>
            @endif
        </div>
    </div>

    @can('post', $receipt)
        <flux:modal name="post-receipt" focusable class="max-w-xl">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Post this production receipt?') }}</flux:heading>
                    <flux:subheading>{{ __('The received output goes into stock as a new lot. A posted receipt can no longer be changed.') }}</flux:subheading>
                </div>
                @if (bccomp($shortage, '0', 2) > 0)
                    <p class="rounded-md border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-3 py-2 text-xs text-red-700 dark:text-red-400">
                        {{ __(':count :unit fewer than reported will be recorded as a shortage.', ['count' => $this->formatQuantity($shortage), 'unit' => $unit]) }}
                    </p>
                @endif
                <x-journal-confirm :plan="$this->journalPlan()" :hide-amounts="! \Illuminate\Support\Facades\Gate::allows('costs.view')" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="post" wire:loading.attr="disabled" wire:target="post" class="active:scale-[0.95]">{{ __('Post') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan

    @can('cancel', $receipt)
        <flux:modal name="cancel-receipt" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Cancel this draft?') }}</flux:heading>
                    <flux:subheading>{{ __('Nothing has entered stock yet. The output stays waiting for a new receipt.') }}</flux:subheading>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Keep draft') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel" class="active:scale-[0.95]">{{ __('Cancel draft') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan
</section>
