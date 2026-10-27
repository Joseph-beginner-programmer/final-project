<?php

use App\Actions\Sales\CancelSalesInvoiceAction;
use App\Actions\Sales\CancelSalesPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\RecordSalesPaymentAction;
use App\Actions\Sales\SaveSalesInvoiceDraftAction;
use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Enums\SalesInvoiceStatus;
use App\Exceptions\InvalidJournalEntryException;
use App\Exceptions\InvalidSalesInvoiceException;
use App\Exceptions\InvalidSalesPaymentException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Support\Decimal;
use App\Support\JournalPreview;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales Invoice')] class extends Component {
    public SalesInvoice $salesInvoice;

    public string $paymentDate = '';

    public string $paymentAmount = '';

    public string $paymentMethod = 'bank_transfer';

    public string $cashAccountId = '';

    public string $paymentReference = '';

    public ?int $pendingPaymentId = null;

    public function mount(SalesInvoice $salesInvoice): void
    {
        Gate::authorize('sales.invoice.view');
        $this->salesInvoice = $salesInvoice;
    }

    protected function reload(): void
    {
        $this->salesInvoice = $this->salesInvoice->fresh();
        unset($this->journals, $this->payments, $this->lines);
    }

    #[Computed]
    public function invoice(): SalesInvoice
    {
        return $this->salesInvoice->load(['customer', 'salesOrder.charges', 'salesDelivery', 'createdBy', 'postedBy']);
    }

    #[Computed]
    public function lines(): array
    {
        return SaveSalesInvoiceDraftAction::lines($this->salesInvoice->salesDelivery);
    }

    #[Computed]
    public function payments(): Collection
    {
        return $this->salesInvoice->payments()->with(['cashAccount', 'createdBy'])->orderBy('id')->get();
    }

    #[Computed]
    public function journals(): Collection
    {
        $paymentIds = $this->salesInvoice->payments()->pluck('id');

        return JournalEntry::query()
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('source_type', 'sales_invoice')->where('source_id', $this->salesInvoice->id))
                ->orWhere(fn ($q) => $q->where('source_type', 'sales_payment')->whereIn('source_id', $paymentIds)))
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function postTotals(): array
    {
        return SaveSalesInvoiceDraftAction::totals($this->salesInvoice->salesDelivery, (string) $this->salesInvoice->tax_rate, $this->salesInvoice->id);
    }

    #[Computed]
    public function journalPreview(): array
    {
        return JournalPreview::resolve(PostSalesInvoiceAction::preview($this->salesInvoice));
    }

    #[Computed]
    public function paymentPreview(): array
    {
        $amount = is_numeric($this->paymentAmount) && bccomp((string) $this->paymentAmount, '0', 2) > 0 ? bcadd((string) $this->paymentAmount, '0', 2) : '0';
        $cash = filled($this->cashAccountId) ? (int) $this->cashAccountId : null;

        return JournalPreview::resolve(RecordSalesPaymentAction::plan($amount, $cash), ['debit', 'credit'], __('Choose a cash or bank account'));
    }

    #[Computed]
    public function cashAccounts(): array
    {
        $reserved = array_map(fn ($role) => $role->value, RecordSalesPaymentAction::reservedRoles());

        return Account::active()
            ->where('type', AccountType::Asset->value)
            ->where(fn ($q) => $q->whereNull('system_role')->orWhereNotIn('system_role', $reserved))
            ->orderBy('account_code')
            ->get()
            ->map(fn (Account $a) => ['id' => (string) $a->id, 'title' => $a->account_name, 'subtitle' => $a->account_code, 'meta' => null, 'disabled_reason' => null])
            ->all();
    }

    public function post(): void
    {
        Gate::authorize('sales.invoice');

        try {
            app(PostSalesInvoiceAction::class)->handle($this->salesInvoice, Auth::id());
        } catch (InvalidSalesInvoiceException|InvalidJournalEntryException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->reload();
        $this->modal('post-invoice')->close();
        Flux::toast(variant: 'success', text: __('Invoice posted. The customer now owes :amount.', ['amount' => $this->rupiah($this->salesInvoice->total_amount)]));
    }

    public function cancel(): void
    {
        Gate::authorize('sales.invoice');

        try {
            app(CancelSalesInvoiceAction::class)->handle($this->salesInvoice, Auth::id());
        } catch (InvalidSalesInvoiceException|InvalidJournalEntryException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->reload();
        $this->modal('cancel-invoice')->close();
        Flux::toast(variant: 'success', text: __('Invoice cancelled.'));
    }

    public function openPayment(): void
    {
        Gate::authorize('sales.invoice');
        $this->resetValidation();
        $this->paymentDate = now()->toDateString();
        $this->paymentAmount = Decimal::plain($this->salesInvoice->balance());
        $this->paymentMethod = PaymentMethod::BankTransfer->value;
        $this->paymentReference = '';
        $this->modal('record-payment')->show();
    }

    public function recordPayment(): void
    {
        Gate::authorize('sales.invoice');

        $this->validate([
            'paymentDate' => ['required', 'date', 'before_or_equal:today'],
            'paymentAmount' => ['required', 'numeric', 'gt:0'],
            'paymentMethod' => ['required', Rule::enum(PaymentMethod::class)],
            'cashAccountId' => ['required', 'exists:accounts,id'],
            'paymentReference' => ['nullable', 'string', 'max:100'],
        ], [], [
            'paymentDate' => __('Payment date'),
            'paymentAmount' => __('Amount'),
            'paymentMethod' => __('Method'),
            'cashAccountId' => __('Received into'),
            'paymentReference' => __('Reference'),
        ]);

        try {
            $payment = app(RecordSalesPaymentAction::class)->handle(
                $this->salesInvoice,
                $this->paymentDate,
                (string) $this->paymentAmount,
                PaymentMethod::from($this->paymentMethod),
                (int) $this->cashAccountId,
                $this->paymentReference,
                Auth::id(),
            );
        } catch (InvalidSalesPaymentException|InvalidJournalEntryException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->reload();
        $this->modal('record-payment')->close();
        Flux::toast(variant: 'success', text: __('Payment :number recorded.', ['number' => $payment->payment_number]));
    }

    public function openCancelPayment(int $paymentId): void
    {
        Gate::authorize('sales.invoice');
        $this->pendingPaymentId = $paymentId;
        $this->modal('cancel-payment')->show();
    }

    public function pendingPaymentPlan(): array
    {
        if (! $this->pendingPaymentId) {
            return [];
        }

        return JournalPreview::reversalOf(JournalEntry::query()
            ->where('source_type', 'sales_payment')->where('source_id', $this->pendingPaymentId)
            ->whereNull('reversal_of_id')->where('status', 'posted')->first());
    }

    public function invoiceReversalPlan(): array
    {
        return JournalPreview::reversalOf(JournalEntry::query()
            ->where('source_type', 'sales_invoice')->where('source_id', $this->salesInvoice->id)
            ->whereNull('reversal_of_id')->where('status', 'posted')->first());
    }

    public function cancelPayment(int $paymentId): void
    {
        Gate::authorize('sales.invoice');
        $payment = SalesPayment::where('sales_invoice_id', $this->salesInvoice->id)->findOrFail($paymentId);

        try {
            app(CancelSalesPaymentAction::class)->handle($payment, Auth::id());
        } catch (InvalidSalesPaymentException|InvalidJournalEntryException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->reload();
        $this->modal('cancel-payment')->close();
        Flux::toast(variant: 'success', text: __('Payment :number cancelled.', ['number' => $payment->payment_number]));
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

@php($inv = $this->invoice)
@php($status = $inv->status)
@php($isDraft = $status === SalesInvoiceStatus::Draft)
@php($canManage = Gate::allows('sales.invoice'))
@php($payable = $canManage && in_array($status, [SalesInvoiceStatus::Unpaid, SalesInvoiceStatus::PartiallyPaid], true))
@php($hasPayments = $this->payments->where('status', DocumentStatus::Posted)->isNotEmpty())
@php($label = 'text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')
@php($rate = Decimal::plain($inv->tax_rate))

<section class="w-full max-w-5xl mx-auto">
    <flux:breadcrumbs>
        @can('sales.invoice')
            <flux:breadcrumbs.item :href="route('accounting.sales-invoices.list', ['tab' => 'invoices'])" wire:navigate>{{ __('Sales Invoices') }}</flux:breadcrumbs.item>
        @else
            <flux:breadcrumbs.item :href="route('sales.orders.show', $inv->salesOrder)" wire:navigate>{{ $inv->salesOrder->so_number }}</flux:breadcrumbs.item>
        @endcan
        <flux:breadcrumbs.item>{{ $inv->invoice_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight font-data">{{ $inv->invoice_number }}</h1>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $status->badgeClasses() }}">{{ $status->label() }}</span>
                @if ($inv->isOverdue())
                    <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2.5 py-0.5 text-xs font-medium text-red-700 dark:text-red-400"><flux:icon.exclamation-triangle variant="micro" class="size-3.5" />{{ __('Overdue') }}</span>
                @endif
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $inv->customer->customer_name }} · {{ $inv->salesOrder->so_number }} → {{ $inv->salesDelivery->delivery_number }} ({{ __('delivered :date', ['date' => $inv->salesDelivery->delivered_at?->translatedFormat('d M Y')]) }})</p>
        </div>
        @if ($canManage)
            <div class="flex flex-wrap items-center gap-2">
                @if ($isDraft)
                    <flux:button size="sm" variant="filled" icon="pencil-square" :href="route('accounting.sales-invoices.edit', $inv)" wire:navigate class="active:scale-[0.95]">{{ __('Edit') }}</flux:button>
                    <flux:modal.trigger name="post-invoice"><flux:button size="sm" variant="primary" icon="check" class="active:scale-[0.95]">{{ __('Post') }}</flux:button></flux:modal.trigger>
                @endif
                @if ($payable)
                    <flux:button size="sm" variant="primary" icon="banknotes" wire:click="openPayment" class="active:scale-[0.95]">{{ __('Record payment') }}</flux:button>
                @endif
                @if ($status !== SalesInvoiceStatus::Cancelled && ! $hasPayments)
                    <flux:modal.trigger name="cancel-invoice"><flux:button size="sm" variant="ghost" icon="x-mark" class="text-zinc-500 hover:text-red-600 active:scale-[0.95]">{{ __('Cancel invoice') }}</flux:button></flux:modal.trigger>
                @endif
            </div>
        @endif
    </div>

    <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach ([
            [__('Total receivable'), $this->rupiah($inv->total_amount), __('incl. VAT :rate%', ['rate' => $rate])],
            [__('Amount received'), $this->rupiah($inv->amount_paid), __(':count payments', ['count' => $this->payments->where('status', DocumentStatus::Posted)->count()])],
            [__('Balance'), $status->isPosted() ? $this->rupiah($inv->balance()) : '—', $status->isPosted() ? __('due :date', ['date' => $inv->due_date->translatedFormat('d M Y')]) : __('not posted yet')],
            [__('Invoice date'), $inv->invoice_date->translatedFormat('d M Y'), __('Terms: :terms', ['terms' => $inv->salesOrder->payment_terms?->label() ?? '—'])],
        ] as [$tileLabel, $value, $note])
            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-4">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $tileLabel }}</p>
                <p class="mt-2 text-lg font-semibold text-zinc-900 dark:text-white">{{ $value }}</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $note }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 min-w-0 space-y-6">
            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900">
                <table class="w-full min-w-[32rem] text-xs border-collapse">
                    <thead>
                        <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                            <th class="py-2 px-3 {{ $label }}">{{ __('Item') }}</th>
                            <th class="py-2 px-3 {{ $label }} text-right">{{ __('Qty') }}</th>
                            <th class="py-2 px-3 {{ $label }} text-right">{{ __('Unit price') }}</th>
                            <th class="py-2 px-3 {{ $label }} text-right">{{ __('Amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->lines as $line)
                            <tr class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                                <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ $line['product_name'] }}</td>
                                <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-800 dark:text-zinc-200">{{ $this->quantity($line['quantity']) }} {{ $line['unit'] }}</td>
                                <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-800 dark:text-zinc-200">{{ $this->rupiah($line['unit_price']) }}</td>
                                <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap font-medium text-zinc-900 dark:text-white">{{ $this->rupiah($line['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-4">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Customer payments') }}</h2>
                    @if ($payable)
                        <flux:button size="sm" variant="ghost" icon="plus" wire:click="openPayment" class="active:scale-[0.95]">{{ __('Record payment') }}</flux:button>
                    @endif
                </div>
                <div class="mt-2 divide-y divide-zinc-100 dark:divide-white/5">
                    @forelse ($this->payments as $payment)
                        @php($cancelled = $payment->status === DocumentStatus::Cancelled)
                        <div wire:key="pay-{{ $payment->id }}" class="flex flex-wrap items-center justify-between gap-3 py-2.5 text-xs {{ $cancelled ? 'opacity-50' : '' }}">
                            <div>
                                <p><span class="font-data text-zinc-900 dark:text-white {{ $cancelled ? 'line-through' : '' }}">{{ $payment->payment_number }}</span> · {{ $payment->payment_date->translatedFormat('d M Y') }} · {{ $payment->method->label() }}</p>
                                <p class="text-zinc-500 dark:text-zinc-400">{{ __('Into :account', ['account' => $payment->cashAccount->account_name]) }}@if ($payment->reference) · {{ $payment->reference }}@endif @if ($cancelled) · {{ __('Cancelled') }}@endif</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="font-data tabular-nums font-medium text-zinc-900 dark:text-white">{{ $this->rupiah($payment->amount) }}</span>
                                @if (! $cancelled && $canManage)
                                    <flux:button size="xs" variant="ghost" icon="arrow-uturn-left" wire:click="openCancelPayment({{ $payment->id }})" :aria-label="__('Cancel payment')" class="text-zinc-400 hover:text-red-600 active:scale-[0.95]" />
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="py-4 text-center text-xs text-zinc-500 dark:text-zinc-400">{{ $status->isPosted() ? __('No payments yet.') : __('Post the invoice before recording payments.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
                <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Summary') }}</h2>
                <dl class="mt-3 space-y-2 text-xs">
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Goods') }}</dt><dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->rupiah($inv->goods_amount) }}</dd></div>
                    @if (bccomp((string) $inv->discount_percent, '0', 2) > 0)
                        <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Discount :rate%', ['rate' => Decimal::plain($inv->discount_percent)]) }}</dt><dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">−{{ $this->rupiah($inv->discount_amount) }}</dd></div>
                    @endif
                    @if (bccomp((string) $inv->charges_amount, '0', 2) > 0)
                        <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Order charges') }}</dt><dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->rupiah($inv->charges_amount) }}</dd></div>
                    @endif
                    <div class="flex justify-between gap-3 border-t border-zinc-100 dark:border-white/10 pt-2"><dt class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Tax base (DPP)') }}</dt><dd class="font-data tabular-nums font-medium text-zinc-900 dark:text-white">{{ $this->rupiah($inv->subtotal) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('VAT :rate%', ['rate' => $rate]) }}</dt><dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->rupiah($inv->tax_amount) }}</dd></div>
                    <div class="flex justify-between gap-3 border-t-2 border-zinc-200 dark:border-white/20 pt-2 text-sm"><dt class="font-semibold text-zinc-900 dark:text-white">{{ __('Total receivable') }}</dt><dd class="font-data tabular-nums font-semibold text-zinc-900 dark:text-white">{{ $this->rupiah($inv->total_amount) }}</dd></div>
                    @if (bccomp((string) $inv->returned_amount, '0', 2) > 0)
                        <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Returns (credit notes)') }}</dt><dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">−{{ $this->rupiah($inv->returned_amount) }}</dd></div>
                        @if (bccomp((string) $inv->refunded_amount, '0', 2) > 0)
                            <div class="flex justify-between gap-3"><dt class="text-zinc-500 dark:text-zinc-400">{{ __('Of which refunded') }}</dt><dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->rupiah($inv->refunded_amount) }}</dd></div>
                        @endif
                        <div class="flex flex-wrap gap-1.5 pt-1">
                            @foreach (\App\Models\SalesReturn::where('sales_invoice_id', $inv->id)->where('status', 'posted')->get() as $ret)
                                <a href="{{ route('sales.returns.show', $ret) }}" wire:navigate class="rounded border border-zinc-200 dark:border-white/10 px-1.5 py-0.5 font-data text-[11px] text-accent hover:underline">{{ $ret->return_number }}</a>
                            @endforeach
                        </div>
                    @endif
                </dl>
            </div>

            @if ($this->journals->isNotEmpty() && Gate::allows('journal.manage'))
                <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Journal entries') }}</h2>
                    <div class="mt-2 space-y-1.5">
                        @foreach ($this->journals as $journal)
                            <a href="{{ route('accounting.journal.show', $journal) }}" wire:navigate class="flex items-center justify-between gap-3 text-xs hover:underline underline-offset-2">
                                <span class="font-data text-accent">{{ $journal->journal_number }}</span>
                                <span class="text-zinc-500 dark:text-zinc-400 {{ $journal->status->value === 'reversed' ? 'line-through' : '' }}">{{ $journal->reversal_of_id ? __('Reversal') : ($journal->source_type === 'sales_payment' ? __('Payment') : __('Invoice')) }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($canManage)
        @if ($isDraft)
        <flux:modal name="post-invoice" class="max-w-xl">
            @php($preview = $this->journalPreview)
            @php($unmapped = collect($preview)->where('unmapped', true))
            @php($changed = $isDraft && bccomp($this->postTotals['total_amount'], (string) $inv->total_amount, 2) !== 0)
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Post this invoice?') }}</flux:heading>
                    <flux:subheading>{{ __('This journal entry will be posted on :date.', ['date' => $inv->invoice_date->translatedFormat('d M Y')]) }}</flux:subheading>
                </div>

                @if ($changed)
                    <p class="rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                        {{ __('The total changed since the draft was saved (now :amount): the order charges were billed on another invoice in the meantime, or that invoice was cancelled.', ['amount' => $this->rupiah($this->postTotals['total_amount'])]) }}
                    </p>
                @endif

                <x-journal-preview :lines="$preview" />

                @if ($unmapped->isNotEmpty())
                    <p class="flex items-start gap-1.5 rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                        <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-3.5 shrink-0" />
                        {{ __('Map these system roles on the Chart of Accounts page before posting: :roles.', ['roles' => $unmapped->pluck('name')->implode(', ')]) }}
                    </p>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="post" wire:loading.attr="disabled" wire:target="post" :disabled="$unmapped->isNotEmpty()">{{ __('Post invoice') }}</flux:button>
                </div>
            </div>
        </flux:modal>
        @endif

        <flux:modal name="cancel-invoice" class="max-w-md">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Cancel this invoice?') }}</flux:heading>
                <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $isDraft ? __('The draft is discarded.') : __('A reversing journal entry is posted and the delivery goes back to "not yet invoiced", so it can be invoiced again.') }}</p>
                @if (! $isDraft)
                    <x-journal-confirm :plan="$this->invoiceReversalPlan()" />
                @endif
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel">{{ __('Cancel invoice') }}</flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal name="record-payment" class="max-w-lg">
            <form wire:submit="recordPayment" class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Record customer payment') }}</flux:heading>
                    <flux:subheading>{{ __('Remaining balance: :amount', ['amount' => $this->rupiah($inv->balance())]) }}</flux:subheading>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:input type="date" wire:model="paymentDate" :label="__('Payment date')" :max="now()->toDateString()" />
                    <flux:field>
                        <flux:label>{{ __('Amount') }}</flux:label>
                        <flux:input.group>
                            <flux:input.group.prefix class="px-2 text-xs">Rp</flux:input.group.prefix>
                            <flux:input type="text" inputmode="decimal" wire:model.live.debounce.400ms="paymentAmount" input:class="text-right font-data tabular-nums" :loading="false" />
                        </flux:input.group>
                        @error('paymentAmount') <flux:error :message="$message" /> @enderror
                    </flux:field>
                </div>
                <flux:select wire:model="paymentMethod" :label="__('Method')">
                    @foreach (PaymentMethod::cases() as $method)
                        <option value="{{ $method->value }}">{{ $method->label() }}</option>
                    @endforeach
                </flux:select>
                <flux:field>
                    <flux:label>{{ __('Received into') }}</flux:label>
                    <x-picker model="cashAccountId" size="md" :options="$this->cashAccounts" :selected="$cashAccountId" :placeholder="__('Cash or bank account...')" :label="__('Received into')" :invalid="$errors->has('cashAccountId')" />
                    @error('cashAccountId') <flux:error class="mt-1" :message="$message" /> @enderror
                </flux:field>
                <flux:input wire:model="paymentReference" :label="__('Reference')" :badge="__('Optional')" :placeholder="__('e.g. transfer number, giro number')" />
                @php($payPreview = $this->paymentPreview)
                @php($payUnmapped = collect($payPreview)->where('unmapped', true))
                <div>
                    <p class="mb-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ __('Journal entry') }}</p>
                    <div wire:loading.class="opacity-60" wire:target="paymentAmount,cashAccountId" class="transition-opacity">
                        <x-journal-preview :lines="$payPreview" />
                    </div>
                    @if ($payUnmapped->isNotEmpty())
                        <p class="mt-2 flex items-start gap-1.5 rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                            <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-3.5 shrink-0" />
                            {{ __('Map these system roles on the Chart of Accounts page before posting: :roles.', ['roles' => $payUnmapped->pluck('name')->implode(', ')]) }}
                        </p>
                    @endif
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="recordPayment" :disabled="$payUnmapped->isNotEmpty()">{{ __('Record payment') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="cancel-payment" class="max-w-xl">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Cancel this payment?') }}</flux:heading>
                    <flux:subheading>{{ __('A reversing journal entry is posted and the amount is added back to the invoice balance.') }}</flux:subheading>
                </div>
                <x-journal-confirm :plan="$this->pendingPaymentPlan()" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="cancelPayment($pendingPaymentId)" wire:loading.attr="disabled" wire:target="cancelPayment">{{ __('Cancel payment') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</section>
