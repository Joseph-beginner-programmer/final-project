<?php

use App\Actions\Sales\CancelSalesReturnAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\RecordSalesPaymentAction;
use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\ReturnCondition;
use App\Exceptions\InvalidJournalEntryException;
use App\Exceptions\InvalidSalesReturnException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Support\JournalPreview;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales Return')] class extends Component {
    public SalesReturn $salesReturn;

    public string $refundAccountId = '';

    public function mount(SalesReturn $salesReturn): void
    {
        Gate::authorize('sales.return.view');
        $this->salesReturn = $salesReturn;
    }

    #[Computed]
    public function ret(): SalesReturn
    {
        return $this->salesReturn->load(['items.product', 'items.deliveryItem.stockMovements', 'customer', 'salesOrder', 'salesDelivery.salesOrder', 'salesInvoice', 'refundAccount', 'createdBy', 'postedBy']);
    }

    #[Computed]
    public function invoice(): ?SalesInvoice
    {
        return $this->ret->status === DocumentStatus::Posted ? $this->ret->salesInvoice : PostSalesReturnAction::invoiceFor($this->ret);
    }

    #[Computed]
    public function plan(): ?array
    {
        if ($this->ret->status !== DocumentStatus::Draft) {
            return null;
        }

        return PostSalesReturnAction::plan($this->ret, $this->invoice, filled($this->refundAccountId) ? (int) $this->refundAccountId : null, false);
    }

    #[Computed]
    public function cashAccounts(): array
    {
        $reserved = array_map(fn ($role) => $role->value, RecordSalesPaymentAction::reservedRoles());

        return Account::active()->where('type', AccountType::Asset->value)
            ->where(fn ($q) => $q->whereNull('system_role')->orWhereNotIn('system_role', $reserved))
            ->orderBy('account_code')->get()
            ->map(fn (Account $a) => ['id' => (string) $a->id, 'title' => $a->account_name, 'subtitle' => $a->account_code, 'meta' => null, 'disabled_reason' => null])
            ->all();
    }

    #[Computed]
    public function journals(): Collection
    {
        return JournalEntry::where('source_type', 'sales_return')->where('source_id', $this->salesReturn->id)->orderBy('id')->get();
    }

    public function post(): void
    {
        Gate::authorize('warehouse.return');

        try {
            app(PostSalesReturnAction::class)->handle($this->salesReturn, Auth::id(), filled($this->refundAccountId) ? (int) $this->refundAccountId : null);
        } catch (InvalidSalesReturnException|InvalidJournalEntryException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->salesReturn = $this->salesReturn->fresh();
        unset($this->ret, $this->invoice, $this->plan, $this->journals);
        $this->modal('post-return')->close();
        Flux::toast(variant: 'success', text: __('Return posted. The goods are back in the warehouse.'));
    }

    public function cancel(): void
    {
        Gate::authorize('sales.return');
        app(CancelSalesReturnAction::class)->handle($this->salesReturn);
        $this->salesReturn = $this->salesReturn->fresh();
        unset($this->ret, $this->plan);
        $this->modal('cancel-return')->close();
        Flux::toast(variant: 'success', text: __('Draft cancelled.'));
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

@php($ret = $this->ret)
@php($isDraft = $ret->status === DocumentStatus::Draft)
@php($isPosted = $ret->status === DocumentStatus::Posted)
@php($invoice = $this->invoice)
@php($plan = $this->plan)
@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')
@php($statusClasses = ['draft' => 'border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 text-amber-800 dark:text-amber-300', 'posted' => 'border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400', 'cancelled' => 'border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/5 text-zinc-600 dark:text-zinc-400'])

<section class="w-full max-w-5xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('sales.returns.list')" wire:navigate>{{ __('Sales Returns') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $ret->return_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-display font-data text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $ret->return_number }}</h1>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $statusClasses[$ret->status->value] }}">{{ $ret->status->label() }}</span>
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                {{ $ret->customer->customer_name }} ·
                <a href="{{ route('sales.orders.show', $ret->salesOrder) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $ret->salesOrder->so_number }}</a> ·
                <span class="font-data">{{ $ret->salesDelivery->delivery_number }}</span> ·
                {{ $ret->return_date->translatedFormat('d M Y') }}
            </p>
        </div>
        @if ($isDraft)
            <div class="flex flex-wrap items-center gap-2">
                @can('sales.return')
                    <flux:button size="sm" variant="filled" icon="pencil-square" :href="route('sales.returns.edit', $ret)" wire:navigate class="active:scale-[0.95]">{{ __('Edit') }}</flux:button>
                @endcan
                @can('warehouse.return')
                    <flux:modal.trigger name="post-return"><flux:button size="sm" variant="primary" icon="check" class="active:scale-[0.95]">{{ __('Post return') }}</flux:button></flux:modal.trigger>
                @endcan
                @can('sales.return')
                    <flux:modal.trigger name="cancel-return"><flux:button size="sm" variant="ghost" icon="x-mark" class="text-zinc-500 hover:text-red-600 active:scale-[0.95]">{{ __('Cancel draft') }}</flux:button></flux:modal.trigger>
                @endcan
            </div>
        @endif
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 min-w-0 space-y-6">
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900">
                <table class="w-full min-w-[34rem] text-xs border-collapse">
                    <thead>
                        <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                            <th class="{{ $th }}">{{ __('Item') }}</th>
                            <th class="{{ $th }}">{{ __('Condition') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Qty') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Price') }}</th>
                            @if ($isPosted)
                                <th class="{{ $th }} text-right">{{ __('Back in stock at cost') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ret->items as $item)
                            <tr class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                                <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ $item->product->product_name }}</td>
                                <td class="py-2.5 px-3">
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] {{ $item->condition === ReturnCondition::Good ? 'border-green-200 dark:border-green-500/20 bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400' : 'border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400' }}">{{ $item->condition->label() }}</span>
                                </td>
                                <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->quantity($item->quantity) }} {{ $item->product->unit_of_measure }}</td>
                                <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->rupiah($item->unit_price) }}</td>
                                @if ($isPosted)
                                    <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $item->condition === ReturnCondition::Good ? $this->rupiah($item->cost_total) : '—' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Reason') }}</p>
                <p class="mt-1 text-sm text-zinc-900 dark:text-white whitespace-pre-line">{{ $ret->reason }}</p>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Invoice') }}</h2>
                @if ($invoice)
                    <p class="mt-2 text-xs text-zinc-600 dark:text-zinc-300">
                        @can('sales.invoice.view')
                            <a href="{{ route('accounting.sales-invoices.show', $invoice) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $invoice->invoice_number }}</a>
                        @else
                            <span class="font-data">{{ $invoice->invoice_number }}</span>
                        @endcan
                        · {{ $invoice->status->label() }} · {{ __('balance :amount', ['amount' => $this->rupiah($invoice->balance())]) }}
                    </p>
                    @if ($isPosted)
                        <dl class="mt-3 space-y-1.5 text-xs">
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Credit note') }}</dt><dd class="font-data tabular-nums text-zinc-900 dark:text-white">{{ $this->rupiah($ret->credit_note_amount) }}</dd></div>
                            @if (bccomp((string) $ret->refund_amount, '0', 2) > 0)
                                <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Refunded from :account', ['account' => $ret->refundAccount?->account_name]) }}</dt><dd class="font-data tabular-nums text-zinc-900 dark:text-white">{{ $this->rupiah($ret->refund_amount) }}</dd></div>
                            @endif
                        </dl>
                    @else
                        <p class="mt-2 text-[11px] leading-relaxed text-zinc-500 dark:text-zinc-400">{{ __('Already invoiced: posting creates a credit note that lowers what the customer owes (and reverses its VAT). Whatever was already paid is refunded.') }}</p>
                    @endif
                @else
                    <p class="mt-2 text-[11px] leading-relaxed text-zinc-500 dark:text-zinc-400">{{ __('Not invoiced yet: the invoice for this delivery will only bill what the customer kept.') }}</p>
                @endif
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5 text-xs">
                <dl class="space-y-1.5">
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Created by') }}</dt><dd class="text-zinc-900 dark:text-white">{{ $ret->createdBy->name }}</dd></div>
                    @if ($isPosted)
                        <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Posted by') }}</dt><dd class="text-zinc-900 dark:text-white">{{ $ret->postedBy?->name }} · {{ $ret->posted_at?->translatedFormat('d M Y') }}</dd></div>
                    @endif
                </dl>
                @if ($this->journals->isNotEmpty() && Gate::allows('journal.manage'))
                    <div class="mt-3 border-t border-zinc-100 dark:border-white/5 pt-3 space-y-1">
                        @foreach ($this->journals as $journal)
                            <a href="{{ route('accounting.journal.show', $journal) }}" wire:navigate class="flex justify-between gap-3 hover:underline underline-offset-2"><span class="font-data text-accent">{{ $journal->journal_number }}</span><span class="text-zinc-500 dark:text-zinc-400">{{ __('Journal') }}</span></a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($isDraft)
        @can('warehouse.return')
            <flux:modal name="post-return" class="max-w-xl">
                @php($preview = JournalPreview::resolve($plan['lines'], null, __('Choose a cash or bank account')))
                @php($unmapped = collect($preview)->where('unmapped', true))
                <div class="space-y-4">
                    <div>
                        <flux:heading size="lg">{{ __('Post this return?') }}</flux:heading>
                        <flux:subheading>{{ __('Resellable goods go back into stock now; damaged goods do not. Posted on :date.', ['date' => $ret->return_date->translatedFormat('d M Y')]) }}</flux:subheading>
                    </div>
                    @if ($plan['refund_needed'])
                        <div class="rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2.5 text-xs text-amber-900 dark:text-amber-200 space-y-2">
                            <p>{{ __('The invoice is already paid, so :amount is refunded to the customer.', ['amount' => $this->rupiah($plan['refund'])]) }}</p>
                            <x-picker model="refundAccountId" size="md" :options="$this->cashAccounts" :selected="$refundAccountId" :placeholder="__('Refunded from...')" :label="__('Refund account')" />
                        </div>
                    @endif
                    @if (count($plan['lines']) >= 2)
                        <x-journal-preview :lines="$preview" />
                    @else
                        <p class="rounded-lg border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/5 px-3 py-2 text-xs text-zinc-600 dark:text-zinc-300">{{ __('No journal entry: nothing goes back into stock and the delivery is not invoiced yet.') }}</p>
                    @endif
                    @if ($unmapped->isNotEmpty())
                        <p class="flex items-start gap-1.5 rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300"><flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-3.5 shrink-0" />{{ __('Map these system roles on the Chart of Accounts page before posting: :roles.', ['roles' => $unmapped->pluck('name')->implode(', ')]) }}</p>
                    @endif
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                        <flux:button variant="primary" wire:click="post" wire:loading.attr="disabled" wire:target="post" :disabled="$unmapped->isNotEmpty() || ($plan['refund_needed'] && blank($refundAccountId))">{{ __('Post return') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endcan
        <flux:modal name="cancel-return" class="max-w-md">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Cancel this draft?') }}</flux:heading>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Nothing has moved yet, so the draft is simply discarded.') }}</p>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="cancel">{{ __('Cancel draft') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</section>
