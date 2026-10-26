<?php

use App\Actions\Sales\SaveSalesInvoiceDraftAction;
use App\Enums\SalesInvoiceStatus;
use App\Exceptions\InvalidSalesInvoiceException;
use App\Models\SalesDelivery;
use App\Models\SalesInvoice;
use App\Support\Decimal;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales Invoice')] class extends Component {
    #[Locked]
    public int $deliveryId;

    #[Locked]
    public ?int $invoiceId = null;

    public string $invoiceDate = '';

    public string $dueDate = '';

    public string $taxRate = '11';

    public function mount(?SalesDelivery $salesDelivery = null, ?SalesInvoice $salesInvoice = null): void
    {
        Gate::authorize('sales.invoice');

        if ($salesInvoice?->exists) {
            if ($salesInvoice->status !== SalesInvoiceStatus::Draft) {
                $this->redirectRoute('accounting.sales-invoices.show', $salesInvoice, navigate: true);

                return;
            }
            $this->invoiceId = $salesInvoice->id;
            $this->deliveryId = $salesInvoice->sales_delivery_id;
            $this->invoiceDate = $salesInvoice->invoice_date->toDateString();
            $this->dueDate = $salesInvoice->due_date->toDateString();
            $this->taxRate = Decimal::plain($salesInvoice->tax_rate);

            return;
        }

        $existing = $salesDelivery->liveInvoice();
        if ($existing) {
            Flux::toast(variant: 'warning', text: (new InvalidSalesInvoiceException(InvalidSalesInvoiceException::ALREADY_INVOICED, $existing->invoice_number))->userMessage());
            $this->redirectRoute($existing->status === SalesInvoiceStatus::Draft ? 'accounting.sales-invoices.edit' : 'accounting.sales-invoices.show', $existing, navigate: true);

            return;
        }

        $this->deliveryId = $salesDelivery->id;
        $this->invoiceDate = now()->toDateString();
        $this->dueDate = $this->defaultDueDate($this->invoiceDate);
    }

    #[Computed]
    public function delivery(): SalesDelivery
    {
        return SalesDelivery::with(['salesOrder.customer', 'salesOrder.charges'])->findOrFail($this->deliveryId);
    }

    #[Computed]
    public function lines(): array
    {
        return SaveSalesInvoiceDraftAction::lines($this->delivery);
    }

    #[Computed]
    public function summary(): array
    {
        return SaveSalesInvoiceDraftAction::totals($this->delivery, (string) $this->taxRate, $this->invoiceId);
    }

    protected function defaultDueDate(string $invoiceDate): string
    {
        $days = $this->delivery->salesOrder->payment_terms?->days() ?? 0;

        return Carbon::parse($invoiceDate)->addDays($days)->toDateString();
    }

    public function updatedInvoiceDate(): void
    {
        if (strtotime($this->invoiceDate)) {
            $this->dueDate = $this->defaultDueDate($this->invoiceDate);
        }
    }

    protected function rules(): array
    {
        return [
            'invoiceDate' => ['required', 'date', 'before_or_equal:today'],
            'dueDate' => ['required', 'date', 'after_or_equal:invoiceDate'],
            'taxRate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'invoiceDate' => __('Invoice date'),
            'dueDate' => __('Due date'),
            'taxRate' => __('VAT rate'),
        ];
    }

    public function save(): void
    {
        Gate::authorize('sales.invoice');
        $this->validate();

        $invoice = $this->invoiceId ? SalesInvoice::findOrFail($this->invoiceId) : null;

        try {
            $invoice = app(SaveSalesInvoiceDraftAction::class)->handle($this->delivery, $this->invoiceDate, $this->dueDate, (string) $this->taxRate, Auth::id(), $invoice);
        } catch (InvalidSalesInvoiceException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Draft :number saved.', ['number' => $invoice->invoice_number]));
        $this->redirectRoute('accounting.sales-invoices.show', $invoice, navigate: true);
    }

    public function rupiah(string|float $value): string
    {
        return ((float) $value < 0 ? '−Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.');
    }

    public function quantity(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}; ?>

@php($delivery = $this->delivery)
@php($order = $delivery->salesOrder)
@php($s = $this->summary)
@php($orderCharges = $order->chargesTotal())
@php($label = 'text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')

<section class="w-full max-w-5xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('accounting.sales-invoices.list')" wire:navigate>{{ __('Sales Invoices') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $invoiceId ? __('Edit draft') : __('Create invoice') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $invoiceId ? __('Edit Sales Invoice') : __('Create Sales Invoice') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('For delivery :delivery of :so to :customer.', ['delivery' => $delivery->delivery_number, 'so' => $order->so_number, 'customer' => $order->customer->customer_name]) }}
            </p>
        </div>
        <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">{{ __('Save draft') }}</flux:button>
    </div>

    <div class="mt-6 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5 sm:p-6">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-start">
            <div>
                <flux:input type="date" wire:model.live="invoiceDate" :label="__('Invoice date')" :max="now()->toDateString()" :min="$delivery->delivered_at?->toDateString()" />
            </div>
            <div>
                <flux:input type="date" wire:model="dueDate" :label="__('Due date')" />
                <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('From the order\'s payment terms: :terms', ['terms' => $order->payment_terms?->label() ?? '—']) }}</p>
            </div>
            <div>
                <flux:input :value="$delivery->delivered_at?->translatedFormat('d M Y')" :label="__('Delivered')" readonly />
            </div>
        </div>
    </div>

    <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Goods') }}</h2>
    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Quantities come from the delivery and prices from the sales order; neither can be changed here.') }}</p>
    <div class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900">
        <table class="w-full min-w-[36rem] text-xs border-collapse">
            <thead>
                <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                    <th class="py-2 px-3 {{ $label }}">{{ __('Item') }}</th>
                    <th class="py-2 px-3 {{ $label }} text-right">{{ __('Delivered qty') }}</th>
                    <th class="py-2 px-3 {{ $label }} text-right">{{ __('Unit price') }}</th>
                    <th class="py-2 px-3 {{ $label }} text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->lines as $line)
                    <tr wire:key="line-{{ $line['product_id'] }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                        <td class="py-2.5 px-3">
                            <p class="text-zinc-900 dark:text-white">{{ $line['product_name'] }}</p>
                            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $line['product_code'] }}</p>
                        </td>
                        <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-800 dark:text-zinc-200">{{ $this->quantity($line['quantity']) }} {{ $line['unit'] }}</td>
                        <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-800 dark:text-zinc-200">{{ $this->rupiah($line['unit_price']) }}</td>
                        <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap font-medium text-zinc-900 dark:text-white">{{ $this->rupiah($line['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            @if ($order->charges->isNotEmpty())
                <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Order charges') }}</h2>
                    <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Charges on the sales order are billed once, on the order\'s first posted invoice.') }}</p>
                    <div class="mt-2 divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach ($order->charges as $charge)
                            <div class="flex items-center justify-between gap-3 py-2 text-xs">
                                <span class="text-zinc-800 dark:text-zinc-200">{{ $charge->description }}</span>
                                <span class="font-data tabular-nums text-zinc-900 dark:text-white">{{ $this->rupiah($charge->amount) }}</span>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-2 rounded-lg px-3 py-2 text-xs {{ bccomp($s['charges_amount'], '0', 2) > 0 ? 'bg-green-50 dark:bg-green-500/10 text-green-800 dark:text-green-300' : 'bg-zinc-50 dark:bg-white/5 text-zinc-600 dark:text-zinc-300' }}">
                        {{ bccomp($s['charges_amount'], '0', 2) > 0 ? __('Included on this invoice.') : __('Already billed on another posted invoice of this order.') }}
                    </p>
                </div>
            @endif
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5 h-fit lg:sticky lg:top-6">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Summary') }}</h2>
            <dl class="mt-3 space-y-2 text-xs">
                <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Goods') }}</dt><dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->rupiah($s['goods_amount']) }}</dd></div>
                @if (bccomp($s['discount_percent'], '0', 2) > 0)
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Discount :rate%', ['rate' => Decimal::plain($s['discount_percent'])]) }}</dt><dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">−{{ $this->rupiah($s['discount_amount']) }}</dd></div>
                @endif
                @if (bccomp($orderCharges, '0', 2) > 0)
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Order charges') }}</dt><dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->rupiah($s['charges_amount']) }}</dd></div>
                @endif
                <div class="flex justify-between gap-3 border-t border-zinc-100 dark:border-white/10 pt-2"><dt class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Tax base (DPP)') }}</dt><dd class="font-data tabular-nums font-medium text-zinc-900 dark:text-white">{{ $this->rupiah($s['subtotal']) }}</dd></div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400">{{ __('VAT') }}
                        <span class="w-20">
                            <flux:input.group>
                                <flux:input size="xs" type="text" inputmode="decimal" input:class="text-right font-data tabular-nums" wire:model.live.debounce.400ms="taxRate" :loading="false" :aria-label="__('VAT rate')" />
                                <flux:input.group.suffix class="px-1.5 text-[11px]">%</flux:input.group.suffix>
                            </flux:input.group>
                        </span>
                    </dt>
                    <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->rupiah($s['tax_amount']) }}</dd>
                </div>
                @error('taxRate') <flux:error :message="$message" /> @enderror
                <div class="flex justify-between gap-3 border-t-2 border-zinc-200 dark:border-white/20 pt-2 text-sm"><dt class="font-semibold text-zinc-900 dark:text-white">{{ __('Total receivable') }}</dt><dd class="font-data tabular-nums font-semibold text-zinc-900 dark:text-white">{{ $this->rupiah($s['total_amount']) }}</dd></div>
            </dl>
        </div>
    </div>
</section>
