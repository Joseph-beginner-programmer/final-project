<?php

use App\Actions\Production\CancelMaterialIssueAction;
use App\Actions\Production\PostMaterialIssueAction;
use App\Enums\DocumentStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use App\Exceptions\MaterialIssueNotEditableException;
use App\Exceptions\WorkOrderNotIssuableException;
use App\Models\MaterialIssue;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Material Issue')] class extends Component {
    public MaterialIssue $materialIssue;

    public function mount(): void
    {
        Gate::authorize('view', $this->materialIssue);
        $this->loadRelations();
    }

    protected function loadRelations(): void
    {
        $this->materialIssue->load([
            'workOrder.product', 'workOrder.materials',
            'items.product', 'items.stockMovements.inventoryLot',
            'createdBy', 'issuedBy',
        ]);
    }

    #[Computed]
    public function plannedProductIds(): array
    {
        return $this->materialIssue->workOrder->materials->pluck('product_id')->all();
    }

    public function post(): void
    {
        Gate::authorize('post', $this->materialIssue);

        try {
            app(PostMaterialIssueAction::class)->handle($this->materialIssue, Auth::id());
        } catch (InsufficientStockException|WorkOrderNotIssuableException|MaterialIssueNotEditableException|InvalidDocumentStatusTransitionException $e) {
            $this->modal('post-issue')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->materialIssue->refresh();
        $this->loadRelations();
        $this->modal('post-issue')->close();
        Flux::toast(variant: 'success', text: __('Posted — materials have left the warehouse.'));
    }

    public function cancel(): void
    {
        Gate::authorize('cancel', $this->materialIssue);

        try {
            app(CancelMaterialIssueAction::class)->handle($this->materialIssue);
        } catch (MaterialIssueNotEditableException|InvalidDocumentStatusTransitionException $e) {
            $this->modal('cancel-issue')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->materialIssue->refresh();
        $this->modal('cancel-issue')->close();
        Flux::toast(variant: 'success', text: __('Material issue cancelled.'));
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

@php($issue = $materialIssue)
@php($posted = $issue->status === DocumentStatus::Posted)
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('warehouse.dashboard')" wire:navigate>{{ __('Warehouse') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('warehouse.outbound.material-issues.list', ['tab' => 'documents'])" wire:navigate>{{ __('Material Issue') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $issue->issue_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-data text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $issue->issue_number }}</h1>
                <span wire:key="status-{{ $issue->status->value }}" wire:transition class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $this->documentBadgeClasses($issue->status) }}">{{ __($issue->status->label()) }}</span>
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                {{ __('For') }} <span class="font-data text-accent">{{ $issue->workOrder->wo_number }}</span> · {{ $issue->workOrder->product->product_name }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" :href="route('warehouse.outbound.material-issues.list', ['tab' => 'documents'])" wire:navigate class="active:scale-[0.95]">{{ __('Back to List') }}</flux:button>
            @can('update', $issue)
                <flux:button variant="filled" icon="pencil-square" :href="route('warehouse.outbound.material-issues.edit', $issue)" wire:navigate class="active:scale-[0.95]">{{ __('Edit') }}</flux:button>
            @endcan
            @can('cancel', $issue)
                <flux:modal.trigger name="cancel-issue">
                    <flux:button variant="ghost" icon="x-mark" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button>
                </flux:modal.trigger>
            @endcan
            @can('post', $issue)
                <flux:modal.trigger name="post-issue">
                    <flux:button variant="primary" icon="arrow-up-tray" class="active:scale-[0.95]">{{ __('Post') }}</flux:button>
                </flux:modal.trigger>
            @endcan
        </div>
    </div>

    {{-- Info --}}
    <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y sm:divide-y-0 sm:divide-x divide-zinc-100 dark:divide-white/5 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="px-4 py-3">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Work Order') }}</p>
            <p class="mt-1 font-data text-sm text-zinc-800 dark:text-zinc-200">{{ $issue->workOrder->wo_number }}</p>
        </div>
        <div class="px-4 py-3">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Created by') }}</p>
            <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-200">{{ $issue->createdBy->name }}</p>
            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $issue->created_at->format('d M Y H:i') }}</p>
        </div>
        <div class="px-4 py-3">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Posted by') }}</p>
            <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-200">{{ $issue->issuedBy?->name ?? '—' }}</p>
            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $issue->issued_at?->format('d M Y H:i') }}</p>
        </div>
        <div class="px-4 py-3">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Total cost') }}</p>
            <p class="mt-1 font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $posted ? $this->formatRupiah($issue->totalCost()) : '—' }}</p>
            @unless ($posted)
                <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Set by FIFO on posting') }}</p>
            @endunless
        </div>
    </div>

    {{-- Lines --}}
    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">{{ __('Materials') }}</h2>
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5">
            @foreach ($issue->items as $item)
                @php($unplanned = ! in_array($item->product_id, $this->plannedProductIds, true))
                <div wire:key="line-{{ $item->id }}" class="px-4 py-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm text-zinc-900 dark:text-white">
                                {{ $item->product->product_name }}
                                @if ($unplanned)
                                    <span class="ml-1 inline-flex items-center rounded-full border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 px-1.5 py-px text-[10px] font-medium text-amber-700 dark:text-amber-400">{{ __('Outside plan') }}</span>
                                @endif
                            </p>
                            <p class="font-data text-[11px] text-accent">{{ $item->product->product_code }}</p>
                            @if ($item->note)
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">“{{ $item->note }}”</p>
                            @endif
                        </div>
                        <div class="text-right">
                            <p class="font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $item->quantity) }} {{ $item->product->unit_of_measure }}</p>
                            @if ($posted)
                                <p class="font-data text-xs tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatRupiah((string) $item->total_cost) }}</p>
                                <p class="font-data text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400">@ {{ $this->formatRupiah($item->unitCost()) }}/{{ $item->product->unit_of_measure }}</p>
                            @endif
                        </div>
                    </div>

                    {{-- FIFO trail: which lots this line drew from --}}
                    @if ($posted && $item->stockMovements->isNotEmpty())
                        <div class="mt-2 rounded-md bg-zinc-50 dark:bg-white/[0.03] px-3 py-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Drawn from (oldest lot first)') }}</p>
                            @foreach ($item->stockMovements->sortBy('id') as $movement)
                                <div class="flex items-center justify-between gap-3 font-data text-[11px] tabular-nums text-zinc-600 dark:text-zinc-400">
                                    <span>{{ $movement->inventoryLot->lot_number }} <span class="text-zinc-400 dark:text-zinc-500">· {{ $movement->inventoryLot->received_at->format('d M Y') }}</span></span>
                                    <span>{{ $this->formatQuantity((string) $movement->quantity) }} × {{ $this->formatRupiah((string) $movement->unit_cost) }} = {{ $this->formatRupiah((string) $movement->total_value) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    @can('post', $issue)
        <flux:modal name="post-issue" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Post this material issue?') }}</flux:heading>
                    <flux:subheading>{{ __('Stock leaves the warehouse now, taken from the oldest lots first. A posted issue can no longer be changed.') }}</flux:subheading>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="post" wire:loading.attr="disabled" wire:target="post" class="active:scale-[0.95]">{{ __('Post') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan

    @can('cancel', $issue)
        <flux:modal name="cancel-issue" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Cancel this draft?') }}</flux:heading>
                    <flux:subheading>{{ __('Nothing has left the warehouse yet, so no stock changes.') }}</flux:subheading>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Keep draft') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel" class="active:scale-[0.95]">{{ __('Cancel draft') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan
</section>
