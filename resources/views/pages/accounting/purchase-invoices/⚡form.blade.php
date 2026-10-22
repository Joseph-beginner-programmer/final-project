<?php

use App\Actions\Purchasing\SavePurchaseInvoiceDraftAction;
use App\DTO\Purchasing\PurchaseInvoiceData;
use App\Enums\AccountType;
use App\Exceptions\InvalidPurchaseInvoiceException;
use App\Models\Account;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrderReceiptBatch;
use App\Support\Decimal;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Purchase Invoice')] class extends Component {
    #[Locked]
    public int $batchId;

    #[Locked]
    public ?int $invoiceId = null;

    public string $invoiceNumber = '';


    public string $invoiceDate = '';

    public string $dueDate = '';

    public string $taxRate = '11';

    public array $prices = [];

    public string $capitalizedCharges = '0';

    public array $charges = [];

    public string $notes = '';

    public function mount(?PurchaseOrderReceiptBatch $batch = null, ?PurchaseInvoice $purchaseInvoice = null): void
    {
        Gate::authorize('purchasing.invoice');

        if ($purchaseInvoice?->exists) {
            if ($purchaseInvoice->status->value !== 'draft') {
                $this->redirectRoute('accounting.purchase-invoices.show', $purchaseInvoice, navigate: true);

                return;
            }
            $this->invoiceId = $purchaseInvoice->id;
            $this->batchId = $purchaseInvoice->purchase_order_receipt_batch_id;
            $this->invoiceNumber = $purchaseInvoice->invoice_number;
            $this->invoiceDate = $purchaseInvoice->invoice_date->toDateString();
            $this->dueDate = $purchaseInvoice->due_date->toDateString();
            $this->taxRate = Decimal::plain($purchaseInvoice->tax_rate);
            $this->capitalizedCharges = Decimal::plain($purchaseInvoice->capitalized_charges);
            $this->notes = (string) $purchaseInvoice->notes;
            foreach ($purchaseInvoice->items as $item) {
                $this->prices[$item->purchase_order_receipt_id] = Decimal::plain($item->unit_price);
            }
            foreach ($purchaseInvoice->charges as $charge) {
                $this->charges[] = ['description' => $charge->description, 'account_id' => (string) $charge->account_id, 'amount' => Decimal::plain($charge->amount)];
            }

            return;
        }

        $existing = $batch->liveInvoice();
        if ($existing) {
            Flux::toast(variant: 'warning', text: (new InvalidPurchaseInvoiceException(InvalidPurchaseInvoiceException::ALREADY_INVOICED, $existing->internal_number))->userMessage());
            $this->redirectRoute($existing->status->value === 'draft' ? 'accounting.purchase-invoices.edit' : 'accounting.purchase-invoices.show', $existing, navigate: true);

            return;
        }

        $this->batchId = $batch->id;
        $this->invoiceDate = now()->toDateString();
        foreach ($batch->purchaseOrderReceipts()->with('purchaseOrderItem')->get() as $receipt) {
            $this->prices[$receipt->id] = Decimal::plain($receipt->purchaseOrderItem->unit_price);
        }
        $this->capitalizedCharges = Decimal::plain(SavePurchaseInvoiceDraftAction::defaultCapitalizedCharges($batch));
    }

    #[Computed]
    public function batch(): PurchaseOrderReceiptBatch
    {
        return PurchaseOrderReceiptBatch::with(['purchaseOrder.supplier', 'purchaseOrderReceipts.purchaseOrderItem.product'])->findOrFail($this->batchId);
    }

    #[Computed]
    public function lines(): array
    {
        $prices = collect($this->prices)->map(fn ($p) => is_numeric($p) && (float) $p >= 0 ? (string) $p : '0')->all();

        return SavePurchaseInvoiceDraftAction::lines($this->batch, $prices);
    }

    #[Computed]
    public function summary(): array
    {
        $other = collect($this->charges)->reduce(fn (string $carry, $c) => is_numeric($c['amount'] ?? null) ? bcadd($carry, (string) $c['amount'], 2) : $carry, '0');

        return SavePurchaseInvoiceDraftAction::totals($this->lines, (string) $this->capitalizedCharges, $other, (string) $this->taxRate);
    }

    #[Computed]
    public function expenseAccounts(): array
    {
        return Account::active()->where('type', AccountType::Expense->value)->orderBy('account_code')->get()->map(fn (Account $a) => [
            'id' => (string) $a->id,
            'title' => $a->account_name,
            'subtitle' => $a->account_code,
            'meta' => null,
            'disabled_reason' => null,
        ])->all();
    }

    public function addCharge(): void
    {
        $this->charges[] = ['description' => '', 'account_id' => '', 'amount' => ''];
    }

    public function removeCharge(int $index): void
    {
        unset($this->charges[$index]);
        $this->charges = array_values($this->charges);
    }

    protected function rules(): array
    {
        return [
            'invoiceNumber' => ['required', 'string', 'max:100'],
            'invoiceDate' => ['required', 'date', 'before_or_equal:today'],
            'dueDate' => ['required', 'date', 'after_or_equal:invoiceDate'],
            'taxRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'prices.*' => ['required', 'numeric', 'min:0'],
            'capitalizedCharges' => ['nullable', 'numeric', 'min:0'],
            'charges.*.description' => ['required', 'string', 'max:255'],
            'charges.*.account_id' => ['required', 'exists:accounts,id'],
            'charges.*.amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'invoiceNumber' => __('Supplier invoice number'),
            'invoiceDate' => __('Invoice date'),
            'dueDate' => __('Due date'),
            'taxRate' => __('VAT rate'),
            'prices.*' => __('Invoice price'),
            'capitalizedCharges' => __('Inventory charges'),
            'charges.*.description' => __('Description'),
            'charges.*.account_id' => __('Expense account'),
            'charges.*.amount' => __('Amount'),
        ];
    }

    public function save(): void
    {
        Gate::authorize('purchasing.invoice');
        $this->charges = array_values(array_filter($this->charges, fn ($c) => filled($c['description']) || filled($c['account_id']) || filled($c['amount'])));
        $this->validate();

        $invoice = $this->invoiceId ? PurchaseInvoice::findOrFail($this->invoiceId) : null;

        try {
            $invoice = app(SavePurchaseInvoiceDraftAction::class)->handle($this->batch, new PurchaseInvoiceData(
                invoiceNumber: $this->invoiceNumber,
                invoiceDate: $this->invoiceDate,
                dueDate: $this->dueDate,
                taxRate: (string) $this->taxRate,
                unitPrices: collect($this->prices)->map(fn ($p) => (string) $p)->all(),
                capitalizedCharges: (string) ($this->capitalizedCharges ?: '0'),
                charges: collect($this->charges)->map(fn ($c) => ['description' => $c['description'], 'account_id' => (int) $c['account_id'], 'amount' => (string) $c['amount']])->all(),
                notes: $this->notes,
            ), Auth::id(), $invoice);
        } catch (InvalidPurchaseInvoiceException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Draft :number saved.', ['number' => $invoice->internal_number]));
        $this->redirectRoute('accounting.purchase-invoices.show', $invoice, navigate: true);
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

@php($batch = $this->batch)
@php($s = $this->summary)
@php($receipts = $batch->purchaseOrderReceipts->keyBy('id'))
@php($variance = (float) $s['price_variance'])
@php($label = 'text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')

<section class="w-full max-w-5xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('accounting.purchase-invoices.list')" wire:navigate>{{ __('Purchase Invoices') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $invoiceId ? __('Edit draft') : __('Record invoice') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $invoiceId ? __('Edit Purchase Invoice') : __('Record Purchase Invoice') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('For receipt :receipt of :po from :supplier.', ['receipt' => $batch->receipt_number, 'po' => $batch->purchaseOrder->po_number, 'supplier' => $batch->purchaseOrder->supplier->supplier_name]) }}
            </p>
        </div>
        <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">{{ __('Save draft') }}</flux:button>
    </div>

    <div class="mt-6 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5 sm:p-6">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <flux:input wire:model="invoiceNumber" :label="__('Supplier invoice number')" :placeholder="__('e.g. INV/2026/10/001')" />
            <flux:input type="date" wire:model="invoiceDate" :label="__('Invoice date')" :max="now()->toDateString()" />
            <flux:input type="date" wire:model="dueDate" :label="__('Due date')" />
        </div>
    </div>

    <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Goods') }}</h2>
    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Quantity comes from the receipt and cannot be changed. Type the price the supplier actually billed.') }}</p>
    <div class="mt-3 overflow-x-auto rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900">
        <table class="w-full min-w-[44rem] text-xs border-collapse">
            <thead>
                <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                    <th class="py-2 px-3 {{ $label }}">{{ __('Item') }}</th>
                    <th class="py-2 px-3 {{ $label }} text-right">{{ __('Received qty') }}</th>
                    <th class="py-2 px-3 {{ $label }} text-right">{{ __('PO price') }}</th>
                    <th class="py-2 px-3 {{ $label }} text-right w-44">{{ __('Invoice price') }}</th>
                    <th class="py-2 px-3 {{ $label }} text-right">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->lines as $line)
                    @php($receipt = $receipts[$line['purchase_order_receipt_id']])
                    @php($poPrice = (string) $receipt->purchaseOrderItem->unit_price)
                    <tr wire:key="line-{{ $receipt->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 align-top">
                        <td class="py-2.5 px-3">
                            <p class="text-zinc-900 dark:text-white">{{ $receipt->purchaseOrderItem->product->product_name }}</p>
                            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $receipt->purchaseOrderItem->product->product_code }}</p>
                        </td>
                        <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-800 dark:text-zinc-200">{{ $this->quantity($line['quantity']) }} {{ $receipt->purchaseOrderItem->product->unit_of_measure }}</td>
                        <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-500 dark:text-zinc-400">{{ $this->rupiah($poPrice) }}</td>
                        <td class="py-2 px-3">
                            <flux:input.group>
                                <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                                <flux:input size="sm" type="text" inputmode="decimal" input:class="text-right font-data tabular-nums" wire:model.live.debounce.400ms="prices.{{ $receipt->id }}" :loading="false" :aria-label="__('Invoice price')" />
                            </flux:input.group>
                            @if (is_numeric($prices[$receipt->id] ?? null) && bccomp((string) $prices[$receipt->id], $poPrice, 2) !== 0)
                                <p class="mt-1 text-right text-[10px] text-amber-700 dark:text-amber-400">{{ __('Differs from PO') }}</p>
                            @endif
                            @error("prices.{$receipt->id}") <flux:error class="mt-1" :message="$message" /> @enderror
                        </td>
                        <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap font-medium text-zinc-900 dark:text-white">{{ $this->rupiah($line['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Inventory charges') }}</h2>
                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('PO charges marked "include in inventory cost" (e.g. freight). This receipt\'s share is pre-filled — change it if the supplier billed a different amount.') }}</p>
                <div class="mt-3 w-56">
                    <flux:input.group>
                        <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                        <flux:input size="sm" type="text" inputmode="decimal" input:class="text-right font-data tabular-nums" wire:model.live.debounce.400ms="capitalizedCharges" :loading="false" :aria-label="__('Inventory charges')" />
                    </flux:input.group>
                    @error('capitalizedCharges') <flux:error class="mt-1" :message="$message" /> @enderror
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Other charges') }}</h2>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Charges that are not inventory cost (admin fee, stamp duty) — booked to the expense account you choose.') }}</p>
                    </div>
                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="addCharge" class="shrink-0 active:scale-[0.95]">{{ __('Add charge') }}</flux:button>
                </div>
                @if ($charges !== [])
                    <div class="mt-3 space-y-3">
                        @foreach ($charges as $index => $charge)
                            <div wire:key="charge-{{ $index }}" class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_9rem_2rem] gap-2 items-start">
                                <div>
                                    <flux:input size="sm" wire:model="charges.{{ $index }}.description" :placeholder="__('e.g. Admin fee')" :aria-label="__('Description')" />
                                    @error("charges.$index.description") <flux:error class="mt-1" :message="$message" /> @enderror
                                </div>
                                <div>
                                    <x-picker :model="'charges.'.$index.'.account_id'" :options="$this->expenseAccounts" :selected="(string) $charge['account_id']" :placeholder="__('Expense account...')" :label="__('Expense account')" :invalid="$errors->has('charges.'.$index.'.account_id')" />
                                    @error("charges.$index.account_id") <flux:error class="mt-1" :message="$message" /> @enderror
                                </div>
                                <div>
                                    <flux:input.group>
                                        <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                                        <flux:input size="sm" type="text" inputmode="decimal" input:class="text-right font-data tabular-nums" wire:model.live.debounce.400ms="charges.{{ $index }}.amount" :loading="false" :aria-label="__('Amount')" />
                                    </flux:input.group>
                                    @error("charges.$index.amount") <flux:error class="mt-1" :message="$message" /> @enderror
                                </div>
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeCharge({{ $index }})" :aria-label="__('Remove')" class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 active:scale-[0.95]" />
                            </div>
                        @endforeach
                    </div>
                    @if ($this->expenseAccounts === [])
                        <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">{{ __('No expense accounts exist yet — add one on the Chart of Accounts page.') }}</p>
                    @endif
                @endif
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
                <flux:textarea wire:model="notes" :label="__('Notes')" :badge="__('Optional')" rows="2" />
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5 h-fit lg:sticky lg:top-6">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Summary') }}</h2>
            <dl class="mt-3 space-y-2 text-xs">
                <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Goods') }}</dt><dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->rupiah($s['goods_amount']) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Inventory charges') }}</dt><dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->rupiah($s['capitalized_charges']) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Other charges') }}</dt><dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->rupiah($s['other_charges']) }}</dd></div>
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
                <div class="flex justify-between gap-3 border-t-2 border-zinc-200 dark:border-white/20 pt-2 text-sm"><dt class="font-semibold text-zinc-900 dark:text-white">{{ __('Total payable') }}</dt><dd class="font-data tabular-nums font-semibold text-zinc-900 dark:text-white">{{ $this->rupiah($s['total_amount']) }}</dd></div>
            </dl>

            <div class="mt-4 rounded-lg px-3 py-2.5 text-xs {{ abs($variance) < 0.005 ? 'bg-zinc-50 dark:bg-white/5 text-zinc-600 dark:text-zinc-300' : 'bg-amber-50 dark:bg-amber-500/10 text-amber-800 dark:text-amber-300' }}">
                <div class="flex justify-between gap-3"><span>{{ __('Value when received') }}</span><span class="font-data tabular-nums">{{ $this->rupiah($s['receipt_value']) }}</span></div>
                <div class="mt-1 flex justify-between gap-3 font-medium"><span>{{ __('Price difference') }}</span><span class="font-data tabular-nums">{{ $variance > 0 ? '+' : '' }}{{ $this->rupiah($s['price_variance']) }}</span></div>
                <p class="mt-1.5 text-[11px] opacity-80">
                    {{ abs($variance) < 0.005 ? __('Matches the received value — no difference to book.') : ($variance > 0 ? __('Supplier billed more than the received value; the difference goes to Selisih Harga Pembelian.') : __('Supplier billed less than the received value; the difference goes to Selisih Harga Pembelian.')) }}
                </p>
            </div>
        </div>
    </div>
</section>
