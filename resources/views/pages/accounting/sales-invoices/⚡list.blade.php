<?php

use App\Enums\DocumentStatus;
use App\Enums\SalesInvoiceStatus;
use App\Models\SalesDelivery;
use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Sales Invoices')] class extends Component {
    use WithPagination;

    #[Url]
    public string $tab = 'pending';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('sales.invoice');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingTab(): void
    {
        $this->resetPage();
        $this->search = '';
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    protected function term(): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $this->search);
    }

    #[Computed]
    public function pending(): Collection
    {
        $term = $this->term();

        return SalesDelivery::query()
            ->where('status', DocumentStatus::Posted->value)
            ->where(fn (Builder $q) => $q
                ->whereDoesntHave('salesInvoices', fn (Builder $q) => $q->where('status', '!=', SalesInvoiceStatus::Cancelled->value))
                ->orWhereHas('salesInvoices', fn (Builder $q) => $q->where('status', SalesInvoiceStatus::Draft->value)))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('delivery_number', 'like', "%{$term}%")
                ->orWhereHas('salesOrder', fn (Builder $q) => $q->where('so_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn (Builder $q) => $q->where('customer_name', 'like', "%{$term}%")))))
            ->with(['salesOrder.customer', 'items.product', 'salesInvoices' => fn ($q) => $q->where('status', SalesInvoiceStatus::Draft->value)])
            ->orderBy('delivered_at')
            ->get()
            ->filter(fn (SalesDelivery $delivery) => $delivery->items->contains(fn ($item) => bccomp($item->netQuantity(), '0', 2) > 0))
            ->values();
    }

    #[Computed]
    public function invoices(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $term = $this->term();

        return SalesInvoice::query()
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('invoice_number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $q) => $q->where('customer_name', 'like', "%{$term}%"))
                ->orWhereHas('salesOrder', fn (Builder $q) => $q->where('so_number', 'like', "%{$term}%"))))
            ->with(['customer', 'salesOrder', 'salesDelivery'])
            ->orderByDesc('id')
            ->paginate(15);
    }

    #[Computed]
    public function outstanding(): array
    {
        $open = SalesInvoice::whereIn('status', [SalesInvoiceStatus::Unpaid->value, SalesInvoiceStatus::PartiallyPaid->value]);

        return [
            'amount' => bcsub((string) (clone $open)->sum('total_amount'), (string) (clone $open)->sum('amount_paid'), 2),
            'count' => (clone $open)->count(),
            'overdue' => (clone $open)->whereDate('due_date', '<', now()->toDateString())->count(),
        ];
    }

    public function rupiah(string|float $value): string
    {
        return 'Rp '.number_format((float) $value, 0, ',', '.');
    }

    public function quantity(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}; ?>

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('accounting.dashboard')" wire:navigate>{{ __('Accounting') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Sales Invoices') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Sales Invoices') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Bill customers for goods already delivered, then record their payments.') }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 px-4 py-3 text-right">
            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Outstanding receivables') }}</p>
            <p class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $this->rupiah($this->outstanding['amount']) }}</p>
            <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                {{ __(':count unpaid invoices', ['count' => $this->outstanding['count']]) }}
                @if ($this->outstanding['overdue'] > 0)
                    · <span class="font-medium text-red-700 dark:text-red-400">{{ __(':count overdue', ['count' => $this->outstanding['overdue']]) }}</span>
                @endif
            </p>
        </div>
    </div>

    <div class="mt-6 flex items-center gap-1.5">
        @foreach (['pending' => __('Deliveries not yet invoiced'), 'invoices' => __('Invoices')] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                    {{ $tab === $key ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
                {{ $label }}
                @if ($key === 'pending')
                    <span class="font-data tabular-nums {{ $tab === $key ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->pending->count() }}</span>
                @endif
            </button>
        @endforeach
    </div>
    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <div class="flex-1 min-w-56">
            <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search"
                :placeholder="$tab === 'pending' ? __('Search delivery, SO or customer...') : __('Search invoice number, SO or customer...')" />
        </div>
        @if ($tab === 'invoices')
            <div class="w-48">
                <flux:select size="sm" wire:model.live="status">
                    <option value="">{{ __('All statuses') }}</option>
                    @foreach (SalesInvoiceStatus::cases() as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </flux:select>
            </div>
        @endif
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,tab,status" class="mt-4 transition-opacity">
        @if ($tab === 'pending')
            <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
                @forelse ($this->pending as $delivery)
                    @php($draft = $delivery->salesInvoices->first())
                    <div wire:key="do-{{ $delivery->id }}" class="flex flex-wrap items-center justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm">
                                <span class="font-data text-accent">{{ $delivery->delivery_number }}</span>
                                <span class="text-zinc-400">·</span>
                                <span class="font-data text-zinc-600 dark:text-zinc-300">{{ $delivery->salesOrder->so_number }}</span>
                                <span class="text-zinc-400">·</span>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $delivery->salesOrder->customer->customer_name }}</span>
                            </p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ __('Delivered :date', ['date' => $delivery->delivered_at?->translatedFormat('d M Y')]) }} ·
                                {{ $delivery->items->map(fn ($i) => $i->product->product_name.' '.$this->quantity((string) $i->quantity).' '.$i->product->unit_of_measure)->implode(', ') }}
                            </p>
                        </div>
                        @if ($draft)
                            <flux:button size="sm" variant="filled" icon="pencil-square" :href="route('accounting.sales-invoices.edit', $draft)" wire:navigate class="active:scale-[0.95]">{{ __('Open draft :number', ['number' => $draft->invoice_number]) }}</flux:button>
                        @else
                            <flux:button size="sm" variant="primary" icon="plus" :href="route('accounting.sales-invoices.create', $delivery)" wire:navigate class="active:scale-[0.95]">{{ __('Create invoice') }}</flux:button>
                        @endif
                    </div>
                @empty
                    <p class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('Every posted delivery already has a posted invoice.') }}</p>
                @endforelse
            </div>
        @else
            <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
                <table class="w-full min-w-[52rem] text-xs border-collapse">
                    <thead>
                        <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                            <th class="py-2 px-3">{{ __('Number') }}</th>
                            <th class="py-2 px-3">{{ __('Customer') }}</th>
                            <th class="py-2 px-3">{{ __('Invoice date') }}</th>
                            <th class="py-2 px-3">{{ __('Due date') }}</th>
                            <th class="py-2 px-3 text-right">{{ __('Total') }}</th>
                            <th class="py-2 px-3 text-right">{{ __('Balance') }}</th>
                            <th class="py-2 px-3">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->invoices as $invoice)
                            <tr wire:key="si-{{ $invoice->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03]">
                                <td class="py-2.5 px-3 whitespace-nowrap">
                                    <a href="{{ route('accounting.sales-invoices.show', $invoice) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $invoice->invoice_number }}</a>
                                    <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $invoice->salesOrder->so_number }} · {{ $invoice->salesDelivery->delivery_number }}</p>
                                </td>
                                <td class="py-2.5 px-3 text-zinc-800 dark:text-zinc-200">{{ $invoice->customer->customer_name }}</td>
                                <td class="py-2.5 px-3 whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $invoice->invoice_date->translatedFormat('d M Y') }}</td>
                                <td class="py-2.5 px-3 whitespace-nowrap {{ $invoice->isOverdue() ? 'font-medium text-red-700 dark:text-red-400' : 'text-zinc-700 dark:text-zinc-300' }}">
                                    {{ $invoice->due_date->translatedFormat('d M Y') }}
                                    @if ($invoice->isOverdue())<span class="ms-1 text-[10px]">{{ __('overdue') }}</span>@endif
                                </td>
                                <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-900 dark:text-white">{{ $this->rupiah($invoice->total_amount) }}</td>
                                <td class="py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $invoice->status->isPosted() ? $this->rupiah($invoice->balance()) : '—' }}</td>
                                <td class="py-2.5 px-3"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap {{ $invoice->status->badgeClasses() }}">{{ $invoice->status->label() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No sales invoices yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $this->invoices->links() }}</div>
        @endif
    </div>
</section>
