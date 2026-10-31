<?php

use App\Actions\Purchasing\ResolvePurchaseRequisitionAction;
use App\Enums\PurchaseRequisitionStatus;
use App\Exceptions\InvalidPurchaseRequisitionException;
use App\Models\PurchaseRequisition;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Purchase Request')] class extends Component {
    public PurchaseRequisition $requisition;

    public string $reason = '';

    public function mount(PurchaseRequisition $purchaseRequisition): void
    {
        Gate::authorize('requisitions.view');
        $this->requisition = $purchaseRequisition->load(['items.product.suppliers', 'items.purchaseOrder', 'createdBy', 'processedBy', 'purchaseOrder']);
    }

    public function cancel(): void
    {
        Gate::authorize('requisitions.create');

        try {
            app(ResolvePurchaseRequisitionAction::class)->cancel($this->requisition, Auth::id());
        } catch (InvalidPurchaseRequisitionException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->requisition->refresh();
        $this->modal('cancel-request')->close();
        Flux::toast(variant: 'success', text: __('Request cancelled.'));
    }

    public function reject(): void
    {
        Gate::authorize('requisitions.process');
        $this->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => __('Reason')]);

        try {
            app(ResolvePurchaseRequisitionAction::class)->reject($this->requisition, $this->reason, Auth::id());
        } catch (InvalidPurchaseRequisitionException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->requisition->refresh();
        $this->modal('reject-request')->close();
        Flux::toast(variant: 'success', text: __('Request declined.'));
    }

    public function quantity(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}; ?>

@php($r = $requisition)
@php($open = $r->status === PurchaseRequisitionStatus::Submitted)

<section class="w-full max-w-4xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('purchasing.requisitions.list')" wire:navigate>{{ __('Purchase Requests') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $r->requisition_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-display font-data text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $r->requisition_number }}</h1>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $r->status->badgeClasses() }}">{{ $r->status->label() }}</span>
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('Requested by :name on :date', ['name' => $r->createdBy->name, 'date' => $r->created_at->translatedFormat('d M Y')]) }}
            </p>
        </div>
        @if ($open)
            <div class="flex flex-wrap items-center gap-2">
                @can('requisitions.process')
                    <flux:button variant="primary" icon="shopping-cart" :href="route('purchasing.orders.create', array_filter(['requisitions' => $r->id, 'supplier' => $r->singleCommonSupplierId()]))" wire:navigate class="active:scale-[0.95]">{{ __('Create PO') }}</flux:button>
                    <flux:modal.trigger name="reject-request"><flux:button variant="filled" icon="x-mark" class="active:scale-[0.95]">{{ __('Decline') }}</flux:button></flux:modal.trigger>
                @endcan
                @can('requisitions.create')
                    <flux:modal.trigger name="cancel-request"><flux:button variant="ghost" class="text-zinc-500 hover:text-red-600 active:scale-[0.95]">{{ __('Cancel request') }}</flux:button></flux:modal.trigger>
                @endcan
            </div>
        @endif
    </div>

    @if ($r->status === PurchaseRequisitionStatus::Processed && $r->purchaseOrder)
        <div class="mt-5 rounded-lg border border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 px-4 py-2.5 text-sm text-green-800 dark:text-green-300">
            {{ __('Turned into') }} <a href="{{ route('purchasing.orders.show', $r->purchaseOrder) }}" wire:navigate class="font-data font-medium underline">{{ $r->purchaseOrder->po_number }}</a>
            {{ __('by :name on :date.', ['name' => $r->processedBy?->name, 'date' => $r->processed_at?->translatedFormat('d M Y')]) }}
        </div>
    @elseif ($open && $r->purchaseOrder)
        <div class="mt-5 rounded-lg border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 px-4 py-2.5 text-sm text-amber-800 dark:text-amber-300">
            <p class="font-medium">{{ __('Open again: its purchase order') }} <a href="{{ route('purchasing.orders.show', $r->purchaseOrder) }}" wire:navigate class="font-data underline">{{ $r->purchaseOrder->po_number }}</a> {{ __('was rejected.') }}</p>
            @if ($r->purchaseOrder->rejection_reason)<p class="mt-0.5">{{ $r->purchaseOrder->rejection_reason }}</p>@endif
            <p class="mt-0.5 text-xs">{{ __('If that PO is revised and resubmitted, this request goes back to it automatically.') }}</p>
        </div>
    @elseif ($r->status === PurchaseRequisitionStatus::Rejected)
        <div class="mt-5 rounded-lg border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-4 py-2.5 text-sm text-red-800 dark:text-red-300">
            <p class="font-medium">{{ __('Declined by :name on :date', ['name' => $r->processedBy?->name, 'date' => $r->processed_at?->translatedFormat('d M Y')]) }}</p>
            <p class="mt-0.5">{{ $r->rejection_reason }}</p>
        </div>
    @endif

    <div class="mt-6 inline-block rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 px-4 py-3">
        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Needed by') }}</p>
        <p class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">{{ $r->needed_by->translatedFormat('d M Y') }}</p>
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
        <table class="w-full text-xs border-collapse">
            <thead>
                <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                    <th class="py-2 px-3">{{ __('Material') }}</th>
                    <th class="py-2 px-3 text-right">{{ __('Requested') }}</th>
                    <th class="py-2 px-3 text-right">{{ __('In stock now') }}</th>
                    <th class="py-2 px-3 text-right">{{ __('Order status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($r->items as $item)
                    <tr class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                        <td class="py-2.5 px-3"><span class="text-zinc-900 dark:text-white">{{ $item->product->product_name }}</span> <span class="font-data text-[11px] text-zinc-500">{{ $item->product->product_code }}</span></td>
                        <td class="py-2.5 px-3 text-right font-data tabular-nums font-semibold text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->quantity((string) $item->quantity) }} {{ $item->product->unit_of_measure }}</td>
                        <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ $this->quantity((string) $item->product->current_stock) }} {{ $item->product->unit_of_measure }}</td>
                        <td class="py-2.5 px-3 text-right whitespace-nowrap">
                            @if ($item->isFulfilled())
                                <a href="{{ route('purchasing.orders.show', $item->purchaseOrder) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $item->purchaseOrder->po_number }}</a>
                            @else
                                <span class="text-zinc-400 dark:text-zinc-500">{{ __('Not yet ordered') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @can('requisitions.process')
    <flux:modal name="reject-request" class="max-w-md">
        <form wire:submit="reject" class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Decline this request?') }}</flux:heading>
                <flux:subheading>{{ __('Production sees the reason. They can send a new request if still needed.') }}</flux:subheading>
            </div>
            <flux:textarea wire:model="reason" :label="__('Reason')" rows="3" :placeholder="__('e.g. enough stock is already on an open PO')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" type="submit">{{ __('Decline') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    @endcan

    @can('requisitions.create')
    <flux:modal name="cancel-request" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Cancel this request?') }}</flux:heading>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Purchasing will no longer see it as open.') }}</p>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="cancel">{{ __('Cancel request') }}</flux:button>
            </div>
        </div>
    </flux:modal>
    @endcan
</section>
