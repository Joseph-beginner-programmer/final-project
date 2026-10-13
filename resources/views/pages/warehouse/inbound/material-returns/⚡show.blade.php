<?php

use App\Actions\Warehouse\CancelMaterialReturnAction;
use App\Actions\Warehouse\PostMaterialReturnAction;
use App\Enums\DocumentStatus;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use App\Exceptions\InvalidMaterialReturnException;
use App\Exceptions\MaterialReturnNotEditableException;
use App\Models\MaterialReturn;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Material Return')] class extends Component {
    public MaterialReturn $materialReturn;

    public function mount(): void
    {
        Gate::authorize('view', $this->materialReturn);
        $this->loadRelations();
    }

    protected function loadRelations(): void
    {
        $this->materialReturn->load([
            'workOrder.product', 'productionResult',
            'items.product', 'items.materialIssueItem.materialIssue', 'items.stockMovements.inventoryLot',
            'createdBy', 'receivedBy',
        ]);
    }

    public function post(): void
    {
        Gate::authorize('post', $this->materialReturn);

        try {
            app(PostMaterialReturnAction::class)->handle($this->materialReturn, Auth::id());
        } catch (InvalidMaterialReturnException|MaterialReturnNotEditableException|InvalidDocumentStatusTransitionException $e) {
            $this->modal('post-return')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->materialReturn->refresh();
        $this->loadRelations();
        $this->modal('post-return')->close();
        Flux::toast(variant: 'success', text: __('Posted — the material is back in stock.'));
    }

    public function cancel(): void
    {
        Gate::authorize('cancel', $this->materialReturn);

        try {
            app(CancelMaterialReturnAction::class)->handle($this->materialReturn);
        } catch (MaterialReturnNotEditableException|InvalidDocumentStatusTransitionException $e) {
            $this->modal('cancel-return')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->materialReturn->refresh();
        $this->modal('cancel-return')->close();
        Flux::toast(variant: 'success', text: __('Material return cancelled.'));
    }

    public function formatQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    public function formatSigned(string $value): string
    {
        return (bccomp($value, '0', 2) > 0 ? '+' : '').$this->formatQuantity($value);
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

@php($return = $materialReturn)
@php($posted = $return->status === DocumentStatus::Posted)
@php($showCost = auth()->user()->can('costs.view'))
@php($label = 'text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('warehouse.dashboard')" wire:navigate>{{ __('Warehouse') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('warehouse.inbound.material-returns.list', ['tab' => 'documents'])" wire:navigate>{{ __('Material Return') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $return->return_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-data text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $return->return_number }}</h1>
                <span wire:key="status-{{ $return->status->value }}" wire:transition class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $this->documentBadgeClasses($return->status) }}">{{ $return->status->label() }}</span>
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                {{ __('From') }} <span class="font-data text-accent">{{ $return->workOrder->wo_number }}</span> · {{ $return->workOrder->product->product_name }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" :href="route('warehouse.inbound.material-returns.list', ['tab' => 'documents'])" wire:navigate class="active:scale-[0.95]">{{ __('Back to List') }}</flux:button>
            @can('update', $return)
                <flux:button variant="filled" icon="pencil-square" :href="route('warehouse.inbound.material-returns.edit', $return)" wire:navigate class="active:scale-[0.95]">{{ __('Edit') }}</flux:button>
            @endcan
            @can('cancel', $return)
                <flux:modal.trigger name="cancel-return">
                    <flux:button variant="ghost" icon="x-mark" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button>
                </flux:modal.trigger>
            @endcan
            @can('post', $return)
                <flux:modal.trigger name="post-return">
                    <flux:button variant="primary" icon="arrow-uturn-left" class="active:scale-[0.95]">{{ __('Post') }}</flux:button>
                </flux:modal.trigger>
            @endcan
        </div>
    </div>

    <div class="mt-6 grid grid-cols-2 {{ $showCost ? 'sm:grid-cols-4' : 'sm:grid-cols-3' }} rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y sm:divide-y-0 sm:divide-x divide-zinc-100 dark:divide-white/5 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="px-4 py-3">
            <p class="{{ $label }}">{{ __('Production finished') }}</p>
            <p class="mt-1 font-data text-sm text-zinc-800 dark:text-zinc-200">{{ $return->productionResult->production_date->format('d M Y') }}</p>
            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $return->productionResult->result_number }}</p>
        </div>
        <div class="px-4 py-3">
            <p class="{{ $label }}">{{ __('Created by') }}</p>
            <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-200">{{ $return->createdBy->name }}</p>
            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $return->created_at->format('d M Y H:i') }}</p>
        </div>
        <div class="px-4 py-3">
            <p class="{{ $label }}">{{ __('Received by') }}</p>
            <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-200">{{ $return->receivedBy?->name ?? '—' }}</p>
            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $return->received_at?->format('d M Y H:i') }}</p>
        </div>
        @if ($showCost)
            <div class="px-4 py-3">
                <p class="{{ $label }}">{{ __('Material variance') }}</p>
                <p class="mt-1 font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $posted ? $this->formatRupiah($return->varianceValue()) : '—' }}</p>
                <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $posted ? __('+ shortfall (loss), − surplus') : __('Set on posting') }}</p>
            </div>
        @endif
    </div>

    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">{{ __('Leftover material') }}</h2>
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5">
            @foreach ($return->items as $item)
                @php($diff = bcsub((string) $item->quantity, (string) $item->quantity_expected, 2))
                @php($unit = $item->product->unit_of_measure)
                <div wire:key="line-{{ $item->id }}" class="px-4 py-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm text-zinc-900 dark:text-white">{{ $item->product->product_name }}</p>
                            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Issued on') }} {{ $item->materialIssueItem->materialIssue->issue_number }}</p>
                        </div>
                        <div class="grid grid-cols-3 gap-4 text-right text-xs">
                            <div>
                                <p class="{{ $label }}">{{ __('Expected back') }}</p>
                                <p class="mt-0.5 font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity((string) $item->quantity_expected) }} {{ $unit }}</p>
                            </div>
                            <div>
                                <p class="{{ $label }}">{{ __('Received') }}</p>
                                <p class="mt-0.5 font-data font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $item->quantity) }} {{ $unit }}</p>
                            </div>
                            <div>
                                <p class="{{ $label }}">{{ __('Difference') }}</p>
                                <p class="mt-0.5 font-data tabular-nums {{ bccomp($diff, '0', 2) === 0 ? 'text-zinc-500 dark:text-zinc-400' : (bccomp($diff, '0', 2) < 0 ? 'text-red-600 dark:text-red-400' : 'text-amber-700 dark:text-amber-400') }}">{{ $this->formatSigned($diff) }} {{ $unit }}</p>
                            </div>
                        </div>
                    </div>

                    @if ($posted && $showCost)
                        <p class="mt-1 text-right font-data text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400">
                            {{ __('Returned value') }} {{ $this->formatRupiah((string) $item->total_cost) }} · {{ __('Variance') }} {{ $this->formatRupiah((string) $item->variance_value) }}
                        </p>
                    @endif

                    @if ($posted && $item->stockMovements->isNotEmpty())
                        <div class="mt-2 rounded-md bg-zinc-50 dark:bg-white/[0.03] px-3 py-2">
                            <p class="{{ $label }} mb-1">{{ __('Returned to (last drawn lot first)') }}</p>
                            @foreach ($item->stockMovements->sortBy('id') as $movement)
                                <div class="flex items-center justify-between gap-3 font-data text-[11px] tabular-nums text-zinc-600 dark:text-zinc-400">
                                    <span>{{ $movement->inventoryLot->lot_number }} <span class="text-zinc-400 dark:text-zinc-500">· {{ $movement->inventoryLot->received_at->format('d M Y') }}</span></span>
                                    @if ($showCost)
                                        <span>{{ $this->formatQuantity((string) $movement->quantity) }} × {{ $this->formatRupiah((string) $movement->unit_cost) }} = {{ $this->formatRupiah((string) $movement->total_value) }}</span>
                                    @else
                                        <span>{{ $this->formatQuantity((string) $movement->quantity) }} {{ $unit }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    @can('post', $return)
        <flux:modal name="post-return" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Post this material return?') }}</flux:heading>
                    <flux:subheading>{{ __('The received material goes back into stock, into the lots it was last drawn from. A posted return can no longer be changed.') }}</flux:subheading>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="post" wire:loading.attr="disabled" wire:target="post" class="active:scale-[0.95]">{{ __('Post') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan

    @can('cancel', $return)
        <flux:modal name="cancel-return" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Cancel this draft?') }}</flux:heading>
                    <flux:subheading>{{ __('Nothing has come back into stock yet. The leftover stays waiting for a new return.') }}</flux:subheading>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Keep draft') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel" class="active:scale-[0.95]">{{ __('Cancel draft') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan
</section>
