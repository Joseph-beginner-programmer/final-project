<?php

use App\Actions\Purchasing\SavePurchaseReturnDraftAction;
use App\Enums\DocumentStatus;
use App\Models\PurchaseOrderReceiptBatch;
use App\Models\PurchaseReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Purchase Returns')] class extends Component {
    #[Url]
    public string $tab = 'returns';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('purchasing.return.view');
        if (! in_array($this->tab, ['returns', 'receipts'], true)) {
            $this->tab = 'returns';
        }
    }

    protected function term(): string
    {
        return trim($this->search);
    }

    #[Computed]
    public function returns(): Collection
    {
        $term = $this->term();

        return PurchaseReturn::query()
            ->with(['supplier', 'purchaseOrder', 'receiptBatch'])
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('return_number', 'like', "%{$term}%")
                ->orWhereHas('purchaseOrder', fn (Builder $q) => $q->where('po_number', 'like', "%{$term}%"))
                ->orWhereHas('supplier', fn (Builder $q) => $q->where('supplier_name', 'like', "%{$term}%"))))
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function receipts(): Collection
    {
        $term = $this->term();

        return PurchaseOrderReceiptBatch::query()
            ->with(['purchaseOrder.supplier', 'purchaseOrderReceipts.purchaseOrderItem.product', 'purchaseOrderReceipts.inventoryLot', 'purchaseReturns' => fn ($q) => $q->where('status', DocumentStatus::Draft->value)])
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('receipt_number', 'like', "%{$term}%")
                ->orWhereHas('purchaseOrder', fn (Builder $q) => $q->where('po_number', 'like', "%{$term}%")
                    ->orWhereHas('supplier', fn (Builder $q) => $q->where('supplier_name', 'like', "%{$term}%")))))
            ->orderByDesc('received_at')
            ->limit(60)
            ->get()
            ->map(fn (PurchaseOrderReceiptBatch $batch) => [
                'batch' => $batch,
                'lines' => $batch->purchaseOrderReceipts
                    ->map(fn ($receipt) => ['receipt' => $receipt, 'returnable' => SavePurchaseReturnDraftAction::returnable($receipt)])
                    ->filter(fn (array $line) => bccomp($line['returnable'], '0', 2) > 0)
                    ->values(),
            ])
            ->filter(fn (array $row) => $row['lines']->isNotEmpty())
            ->values();
    }

    public function rupiah(string|float $value): string
    {
        return ((float) $value < 0 ? '−Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.');
    }

    public function quantity(string|float $value): string
    {
        $formatted = number_format((float) $value, 2, ',', '.');

        return str_ends_with($formatted, ',00') ? substr($formatted, 0, -3) : rtrim($formatted, '0');
    }
}; ?>

@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')
@php($statusClasses = ['draft' => 'border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 text-amber-800 dark:text-amber-300', 'posted' => 'border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400', 'cancelled' => 'border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/5 text-zinc-600 dark:text-zinc-400'])

<section class="w-full max-w-6xl mx-auto">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Purchase Returns') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Send received goods back to the supplier. Purchasing drafts the return, the warehouse posts it when the goods leave.') }}</p>
        </div>
    </div>

    <div class="mt-6 flex items-center gap-1.5">
        @foreach (['returns' => __('Returns'), 'receipts' => __('Receipts that can be returned')] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97] {{ $tab === $key ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>
    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    <div class="mt-4 max-w-md">
        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="$tab === 'returns' ? __('Search return, PO or supplier...') : __('Search receipt, PO or supplier...')" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,tab" class="mt-4 transition-opacity">
        @if ($tab === 'receipts')
            <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
                @forelse ($this->receipts as $row)
                    @php($batch = $row['batch'])
                    @php($draft = $batch->purchaseReturns->first())
                    <div wire:key="rcv-{{ $batch->id }}" class="flex flex-wrap items-center justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm">
                                <span class="font-data text-accent">{{ $batch->receipt_number }}</span>
                                <span class="text-zinc-400">·</span>
                                <span class="font-data text-zinc-600 dark:text-zinc-300">{{ $batch->purchaseOrder->po_number }}</span>
                                <span class="text-zinc-400">·</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $batch->purchaseOrder->supplier->supplier_name }}</span>
                            </p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ __('Received :date', ['date' => $batch->received_at->translatedFormat('d M Y')]) }} ·
                                {{ $row['lines']->map(fn ($l) => __(':product up to :qty :unit', ['product' => $l['receipt']->purchaseOrderItem->product->product_name, 'qty' => $this->quantity($l['returnable']), 'unit' => $l['receipt']->purchaseOrderItem->product->unit_of_measure]))->implode(', ') }}
                            </p>
                        </div>
                        @can('purchasing.return')
                            @if ($draft)
                                <flux:button size="sm" variant="filled" icon="pencil-square" :href="route('purchasing.returns.edit', $draft)" wire:navigate class="active:scale-[0.95]">{{ __('Open draft :number', ['number' => $draft->return_number]) }}</flux:button>
                            @else
                                <flux:button size="sm" variant="primary" icon="arrow-uturn-left" :href="route('purchasing.returns.create', $batch)" wire:navigate class="active:scale-[0.95]">{{ __('Create return') }}</flux:button>
                            @endif
                        @endcan
                    </div>
                @empty
                    <p class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No received goods are still in stock to return.') }}</p>
                @endforelse
            </div>
        @else
            <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
                <table class="w-full min-w-[48rem] text-xs border-collapse">
                    <thead>
                        <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                            <th class="{{ $th }}">{{ __('Number') }}</th>
                            <th class="{{ $th }}">{{ __('Date') }}</th>
                            <th class="{{ $th }}">{{ __('Supplier') }}</th>
                            <th class="{{ $th }}">{{ __('Receipt') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Value') }}</th>
                            <th class="{{ $th }}">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->returns as $return)
                            <tr wire:key="ret-{{ $return->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                <td class="py-2.5 px-3"><a href="{{ route('purchasing.returns.show', $return) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $return->return_number }}</a></td>
                                <td class="py-2.5 px-3 text-zinc-700 dark:text-zinc-300">{{ $return->return_date->translatedFormat('d M Y') }}</td>
                                <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ $return->supplier->supplier_name }}</td>
                                <td class="py-2.5 px-3 font-data text-zinc-600 dark:text-zinc-300">{{ $return->receiptBatch->receipt_number }} · {{ $return->purchaseOrder->po_number }}</td>
                                <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->rupiah($return->total_amount) }}</td>
                                <td class="py-2.5 px-3"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] {{ $statusClasses[$return->status->value] }}">{{ $return->status->label() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No purchase returns yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>
