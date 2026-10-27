<?php

use App\Actions\Purchasing\SavePurchaseReturnDraftAction;
use App\Exceptions\InvalidPurchaseReturnException;
use App\Models\PurchaseOrderReceiptBatch;
use App\Models\PurchaseReturn;
use App\Support\Decimal;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Purchase Return')] class extends Component {
    #[Locked]
    public int $batchId;

    #[Locked]
    public ?int $returnId = null;

    public string $returnDate = '';

    public string $reason = '';

    /** @var array<int, string> */
    public array $quantities = [];

    public function mount(?PurchaseOrderReceiptBatch $batch = null, ?PurchaseReturn $purchaseReturn = null): void
    {
        Gate::authorize('purchasing.return');

        if ($purchaseReturn?->exists) {
            $purchaseReturn->ensureIsDraft();
            $this->returnId = $purchaseReturn->id;
            $this->batchId = $purchaseReturn->purchase_order_receipt_batch_id;
            $this->returnDate = $purchaseReturn->return_date->toDateString();
            $this->reason = $purchaseReturn->reason;
            foreach ($purchaseReturn->items as $item) {
                $this->quantities[$item->purchase_order_receipt_id] = Decimal::plain($item->quantity);
            }

            return;
        }

        $this->batchId = $batch->id;
        $this->returnDate = now()->toDateString();
    }

    #[Computed]
    public function batch(): PurchaseOrderReceiptBatch
    {
        return PurchaseOrderReceiptBatch::with(['purchaseOrder.supplier', 'purchaseOrderReceipts.purchaseOrderItem.product', 'purchaseOrderReceipts.inventoryLot'])->findOrFail($this->batchId);
    }

    #[Computed]
    public function lines(): Collection
    {
        return $this->batch->purchaseOrderReceipts->map(fn ($receipt) => [
            'receipt' => $receipt,
            'returned' => $receipt->returned()['quantity'],
            'in_stock' => (string) ($receipt->inventoryLot?->quantity_remaining ?? '0'),
            'returnable' => SavePurchaseReturnDraftAction::returnable($receipt),
        ]);
    }

    public function save(): void
    {
        Gate::authorize('purchasing.return');
        $this->validate([
            'returnDate' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:1000'],
            'quantities.*' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'returnDate' => __('Return date'),
            'reason' => __('Reason'),
            'quantities.*' => __('Quantity'),
        ]);

        try {
            $return = app(SavePurchaseReturnDraftAction::class)->handle(
                $this->batch,
                array_map(fn ($q) => (string) $q, $this->quantities),
                $this->returnDate,
                $this->reason,
                Auth::id(),
                $this->returnId ? PurchaseReturn::findOrFail($this->returnId) : null,
            );
        } catch (InvalidPurchaseReturnException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Return :number saved as a draft.', ['number' => $return->return_number]));
        $this->redirectRoute('purchasing.returns.show', $return, navigate: true);
    }

    public function quantity(string|float $value): string
    {
        $formatted = number_format((float) $value, 2, ',', '.');

        return str_ends_with($formatted, ',00') ? substr($formatted, 0, -3) : rtrim($formatted, '0');
    }
}; ?>

@php($batch = $this->batch)
@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')

<section class="w-full max-w-5xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('purchasing.returns.list')" wire:navigate>{{ __('Purchase Returns') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $returnId ? __('Edit draft') : __('New return') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $returnId ? __('Edit purchase return') : __('New purchase return') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                <span class="font-data text-accent">{{ $batch->receipt_number }}</span> · <span class="font-data">{{ $batch->purchaseOrder->po_number }}</span> · {{ $batch->purchaseOrder->supplier->supplier_name }} · {{ __('received :date', ['date' => $batch->received_at->translatedFormat('d M Y')]) }}
            </p>
        </div>
        <flux:button variant="primary" icon="check" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">{{ __('Save draft') }}</flux:button>
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        <div class="lg:col-span-2 min-w-0 overflow-x-auto rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900">
            <table class="w-full min-w-[36rem] text-xs border-collapse">
                <thead>
                    <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="{{ $th }}">{{ __('Item') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Received') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Already returned') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Still in stock') }}</th>
                        <th class="{{ $th }} text-right w-36">{{ __('Return') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->lines as $line)
                        @php($receipt = $line['receipt'])
                        @php($unit = $receipt->purchaseOrderItem->product->unit_of_measure)
                        @php($none = bccomp($line['returnable'], '0', 2) <= 0)
                        <tr wire:key="line-{{ $receipt->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 {{ $none ? 'opacity-50' : '' }}">
                            <td class="py-2.5 px-3">
                                <p class="text-zinc-900 dark:text-white">{{ $receipt->purchaseOrderItem->product->product_name }}</p>
                                <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Up to :qty :unit can still be returned', ['qty' => $this->quantity($line['returnable']), 'unit' => $unit]) }}</p>
                            </td>
                            <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->quantity($receipt->quantity_received) }} {{ $unit }}</td>
                            <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->quantity($line['returned']) }}</td>
                            <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->quantity($line['in_stock']) }}</td>
                            <td class="py-2.5 px-3">
                                <flux:input size="sm" type="text" inputmode="decimal" wire:model="quantities.{{ $receipt->id }}" input:class="text-right font-data tabular-nums" :disabled="$none" placeholder="0" :aria-label="__('Return quantity for :item', ['item' => $receipt->purchaseOrderItem->product->product_name])" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="border-t border-zinc-100 dark:border-white/5 px-4 py-2.5 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Only what is still in this receipt\'s lot can go back: material already issued to production cannot be returned.') }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5 space-y-4">
            <flux:input type="date" wire:model="returnDate" :label="__('Return date')" :max="now()->toDateString()" />
            <flux:textarea wire:model="reason" :label="__('Reason')" rows="4" :placeholder="__('e.g. pellets are wet, wrong colour, damaged packaging')" />
            <p class="text-[11px] leading-relaxed text-zinc-500 dark:text-zinc-400">{{ __('The PO counts returned goods as not received, so the supplier can send a replacement on the same PO. If no replacement is coming, close the PO.') }}</p>
        </div>
    </div>
</section>
