<?php

use App\Actions\Sales\SaveSalesReturnDraftAction;
use App\Enums\ReturnCondition;
use App\Exceptions\InvalidSalesReturnException;
use App\Models\SalesDelivery;
use App\Models\SalesReturn;
use App\Support\Decimal;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales Return')] class extends Component {
    #[Locked]
    public int $deliveryId;

    #[Locked]
    public ?int $returnId = null;

    public string $returnDate = '';

    public string $reason = '';

    /** @var array<int, array{quantity: string, condition: string}> */
    public array $lines = [];

    public function mount(?SalesDelivery $salesDelivery = null, ?SalesReturn $salesReturn = null): void
    {
        Gate::authorize('sales.return');

        if ($salesReturn?->exists) {
            $salesReturn->ensureIsDraft();
            $this->returnId = $salesReturn->id;
            $this->deliveryId = $salesReturn->sales_delivery_id;
            $this->returnDate = $salesReturn->return_date->toDateString();
            $this->reason = $salesReturn->reason;
        } else {
            $this->deliveryId = $salesDelivery->id;
            $this->returnDate = now()->toDateString();
        }

        foreach ($this->delivery->items as $item) {
            $existing = $salesReturn?->items->firstWhere('sales_delivery_item_id', $item->id);
            $this->lines[$item->id] = [
                'quantity' => $existing ? Decimal::plain($existing->quantity) : '',
                'condition' => $existing?->condition->value ?? ReturnCondition::Good->value,
            ];
        }
    }

    #[Computed]
    public function delivery(): SalesDelivery
    {
        return SalesDelivery::with(['salesOrder.customer', 'items.product'])->findOrFail($this->deliveryId);
    }

    public function save(): void
    {
        Gate::authorize('sales.return');
        $this->validate([
            'returnDate' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:1000'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            'returnDate' => __('Return date'),
            'reason' => __('Reason'),
            'lines.*.quantity' => __('Quantity'),
        ]);

        try {
            $return = app(SaveSalesReturnDraftAction::class)->handle(
                $this->delivery,
                array_map(fn (array $l) => ['quantity' => (string) $l['quantity'], 'condition' => (string) $l['condition']], $this->lines),
                $this->returnDate,
                $this->reason,
                Auth::id(),
                $this->returnId ? SalesReturn::findOrFail($this->returnId) : null,
            );
        } catch (InvalidSalesReturnException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Return :number saved as a draft.', ['number' => $return->return_number]));
        $this->redirectRoute('sales.returns.show', $return, navigate: true);
    }

    public function quantity(string|float $value): string
    {
        $formatted = number_format((float) $value, 2, ',', '.');

        return str_ends_with($formatted, ',00') ? substr($formatted, 0, -3) : rtrim($formatted, '0');
    }
}; ?>

@php($delivery = $this->delivery)
@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')

<section class="w-full max-w-5xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('sales.returns.list')" wire:navigate>{{ __('Sales Returns') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $returnId ? __('Edit draft') : __('New return') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $returnId ? __('Edit sales return') : __('New sales return') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                <span class="font-data text-accent">{{ $delivery->delivery_number }}</span> · <span class="font-data">{{ $delivery->salesOrder->so_number }}</span> · {{ $delivery->salesOrder->customer->customer_name }} · {{ __('delivered :date', ['date' => $delivery->delivered_at?->translatedFormat('d M Y')]) }}
            </p>
        </div>
        <flux:button variant="primary" icon="check" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">{{ __('Save draft') }}</flux:button>
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        <div class="lg:col-span-2 min-w-0 overflow-x-auto rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900">
            <table class="w-full min-w-[38rem] text-xs border-collapse">
                <thead>
                    <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="{{ $th }}">{{ __('Item') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Delivered') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Can return') }}</th>
                        <th class="{{ $th }} text-right w-28">{{ __('Return') }}</th>
                        <th class="{{ $th }} w-36">{{ __('Condition') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($delivery->items as $item)
                        @php($left = $item->netQuantity())
                        @php($none = bccomp($left, '0', 2) <= 0)
                        <tr wire:key="line-{{ $item->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 {{ $none ? 'opacity-50' : '' }}">
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ $item->product->product_name }}</td>
                            <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->quantity($item->quantity) }} {{ $item->product->unit_of_measure }}</td>
                            <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->quantity($left) }}</td>
                            <td class="py-2.5 px-3">
                                <flux:input size="sm" type="text" inputmode="decimal" wire:model="lines.{{ $item->id }}.quantity" input:class="text-right font-data tabular-nums" :disabled="$none" placeholder="0" :aria-label="__('Return quantity for :item', ['item' => $item->product->product_name])" />
                            </td>
                            <td class="py-2.5 px-3">
                                <flux:select size="sm" wire:model="lines.{{ $item->id }}.condition" :disabled="$none" :aria-label="__('Condition')">
                                    @foreach (ReturnCondition::cases() as $condition)
                                        <option value="{{ $condition->value }}">{{ $condition->label() }}</option>
                                    @endforeach
                                </flux:select>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="border-t border-zinc-100 dark:border-white/5 px-4 py-2.5 text-[11px] leading-relaxed text-zinc-500 dark:text-zinc-400">{{ __('Resellable goods go back into stock at their original cost. Damaged goods do not go back into stock; their cost stays as cost of goods sold.') }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5 space-y-4">
            <flux:input type="date" wire:model="returnDate" :label="__('Return date')" :max="now()->toDateString()" />
            <flux:textarea wire:model="reason" :label="__('Reason')" rows="4" :placeholder="__('e.g. lid does not seal, cracked body, wrong colour sent')" />
        </div>
    </div>
</section>
