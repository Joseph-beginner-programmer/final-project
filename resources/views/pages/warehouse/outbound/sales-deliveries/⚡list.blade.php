<?php

use App\Enums\DocumentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\SalesDelivery;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Sales Delivery')] class extends Component {
    use WithPagination;

    #[Url]
    public string $tab = 'waiting';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', SalesDelivery::class);
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

    protected function term(): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $this->search);
    }

    #[Computed]
    public function waiting(): Collection
    {
        $term = $this->term();

        return SalesOrder::query()
            ->whereIn('status', [SalesOrderStatus::Confirmed->value, SalesOrderStatus::PartiallyDelivered->value])
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('so_number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $q) => $q->where('customer_name', 'like', "%{$term}%"))))
            ->with(['customer', 'items', 'deliveries' => fn ($q) => $q->where('status', DocumentStatus::Draft->value)])
            ->orderByRaw('expected_delivery_date is null')
            ->orderBy('expected_delivery_date')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function documents(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $term = $this->term();

        return SalesDelivery::query()
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('delivery_number', 'like', "%{$term}%")
                ->orWhereHas('salesOrder', fn (Builder $q) => $q->where('so_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn (Builder $q) => $q->where('customer_name', 'like', "%{$term}%")))))
            ->with(['salesOrder.customer', 'items'])
            ->orderByDesc('id')
            ->paginate(15);
    }

    public function formatQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    public function documentBadgeClasses(DocumentStatus $status): string
    {
        return match ($status) {
            DocumentStatus::Draft => 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:border-white/10',
            DocumentStatus::Posted => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
            DocumentStatus::Cancelled => 'bg-zinc-100 text-zinc-500 border-zinc-200 line-through dark:bg-white/5 dark:text-zinc-500 dark:border-white/10',
        };
    }

    public function orderBadgeClasses(SalesOrderStatus $status): string
    {
        return $status === SalesOrderStatus::PartiallyDelivered
            ? 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-500/10 dark:text-purple-400 dark:border-purple-500/20'
            : 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-400 dark:border-blue-500/20';
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

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item>{{ __('Sales Delivery') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 motion-safe:animate-fade-slide-up">
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Sales Delivery') }}</h1>
        <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
    </div>

    <div class="mt-6 flex items-center gap-1.5 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        @foreach (['waiting' => __('Orders waiting for delivery'), 'documents' => __('Delivery documents')] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                    {{ $tab === $key ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
                {{ $label }}
                @if ($key === 'waiting')
                    <span class="font-data tabular-nums {{ $tab === $key ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->waiting->count() }}</span>
                @endif
            </button>
        @endforeach
    </div>
    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    <div class="mt-4 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search"
            :placeholder="$tab === 'waiting' ? __('Search SO number or customer...') : __('Search delivery, SO number or customer...')" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,tab,gotoPage,previousPage,nextPage" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
        @if ($tab === 'waiting')
            <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
                @forelse ($this->waiting as $order)
                    @php($total = $order->items->count())
                    @php($done = $order->items->filter(fn ($item) => $item->isFullyDelivered())->count())
                    @php($draft = $order->deliveries->first())
                    @php($late = $order->expected_delivery_date && $order->expected_delivery_date->isPast() && ! $order->expected_delivery_date->isToday())
                    <div wire:key="so-{{ $order->id }}" class="flex flex-col sm:flex-row sm:items-center gap-3 px-4 py-3.5 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-data text-sm font-medium text-accent">{{ $order->so_number }}</span>
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->orderBadgeClasses($order->status) }}">{{ $order->status->label() }}</span>
                                @if ($draft)
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->documentBadgeClasses($draft->status) }}">{{ __('Draft') }} {{ $draft->delivery_number }}</span>
                                @endif
                            </div>
                            <p class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">{{ $order->customer->customer_name }}</p>
                            <p class="mt-0.5 text-xs {{ $late ? 'text-red-600 dark:text-red-400' : 'text-zinc-500 dark:text-zinc-400' }}">
                                {{ __('Expected Delivery') }} <span class="font-data tabular-nums">{{ $order->expected_delivery_date?->format('d M Y') ?? __('No delivery date') }}</span>@if ($late) · {{ __('late') }}@endif
                            </p>
                        </div>

                        <div class="sm:w-44">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Products delivered') }}</span>
                                <span class="font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $done }}/{{ $total }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 rounded-full bg-zinc-100 dark:bg-white/10 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $done }}">
                                <div class="h-full w-full origin-left rounded-full bg-accent" style="transform: scaleX({{ $total ? $done / $total : 0 }});"></div>
                            </div>
                        </div>

                        <div class="sm:w-44 flex sm:justify-end">
                            @if ($draft)
                                <flux:button size="sm" variant="filled" icon="pencil-square" :href="route('warehouse.outbound.sales-deliveries.show', $draft)" wire:navigate class="active:scale-[0.95]">{{ __('Open draft') }}</flux:button>
                            @else
                                <flux:button size="sm" variant="primary" icon="truck" :href="route('warehouse.outbound.sales-deliveries.create', $order)" wire:navigate class="active:scale-[0.95]">{{ __('Deliver goods') }}</flux:button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No sales orders are waiting for delivery.') }}</p>
                @endforelse
            </div>
        @else
            <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
                <table class="w-full text-xs border-collapse">
                    <thead>
                        <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                            <th class="py-2 px-3">{{ __('Delivery Number') }}</th>
                            <th class="py-2 px-3">{{ __('Sales Order') }}</th>
                            <th class="py-2 px-3 text-right">{{ __('Quantity') }}</th>
                            <th class="py-2 px-3">{{ __('Delivered at') }}</th>
                            <th class="py-2 px-3">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->documents as $delivery)
                            <tr wire:key="do-{{ $delivery->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                                <td class="py-2 px-3 whitespace-nowrap">
                                    <a href="{{ route('warehouse.outbound.sales-deliveries.show', $delivery) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $delivery->delivery_number }}</a>
                                </td>
                                <td class="py-2 px-3">
                                    <p class="font-data text-zinc-700 dark:text-zinc-300">{{ $delivery->salesOrder->so_number }}</p>
                                    <p class="text-zinc-500 dark:text-zinc-400">{{ $delivery->salesOrder->customer->customer_name }}</p>
                                </td>
                                <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity((string) $delivery->items->sum('quantity')) }}</td>
                                <td class="py-2 px-3 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $delivery->delivered_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td class="py-2 px-3">
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->documentBadgeClasses($delivery->status) }}">{{ $delivery->status->label() }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No deliveries yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $this->documents->links() }}</div>
        @endif
    </div>
</section>
