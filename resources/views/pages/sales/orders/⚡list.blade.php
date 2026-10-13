<?php

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Sales Orders')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', SalesOrder::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    protected function searchQuery(): Builder
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return SalesOrder::query()
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('so_number', 'like', "%{$term}%")
                ->orWhereHas('customer', fn (Builder $q) => $q->where('customer_name', 'like', "%{$term}%"))));
    }

    #[Computed]
    public function orders(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->searchQuery()
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->with(['customer', 'items'])
            ->orderByDesc('id')
            ->paginate(15);
    }

    #[Computed]
    public function statusCounts(): array
    {
        return $this->searchQuery()->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status')->all();
    }

    public function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    public function statusBadgeClasses(SalesOrderStatus $status): string
    {
        return match ($status) {
            SalesOrderStatus::Draft => 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:border-white/10',
            SalesOrderStatus::Confirmed => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-400 dark:border-blue-500/20',
            SalesOrderStatus::PartiallyDelivered => 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-500/10 dark:text-purple-400 dark:border-purple-500/20',
            SalesOrderStatus::FullyDelivered => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
            SalesOrderStatus::Cancelled => 'bg-zinc-100 text-zinc-500 border-zinc-200 line-through dark:bg-white/5 dark:text-zinc-500 dark:border-white/10',
        };
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
        <flux:breadcrumbs.item :href="route('sales.dashboard')" wire:navigate>{{ __('Sales') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Sales Orders') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ __('Sales Orders') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>

        @can('create', SalesOrder::class)
            <flux:button variant="primary" icon="plus" :href="route('sales.orders.create')" wire:navigate class="active:scale-[0.95]">
                {{ __('New Sales Order') }}
            </flux:button>
        @endcan
    </div>

    <div class="mt-6 flex items-center gap-1.5 overflow-x-auto pb-1 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <button type="button" wire:click="$set('status', '')"
            class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                {{ $status === '' ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
            {{ __('All') }}
            <span class="font-data tabular-nums {{ $status === '' ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ array_sum($this->statusCounts) }}</span>
        </button>
        @foreach (SalesOrderStatus::cases() as $option)
            <button type="button" wire:click="$set('status', '{{ $option->value }}')"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                    {{ $status === $option->value ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
                {{ $option->label() }}
                <span class="font-data tabular-nums {{ $status === $option->value ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->statusCounts[$option->value] ?? 0 }}</span>
            </button>
        @endforeach
    </div>
    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    <div class="mt-4 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search SO number or customer...')" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,status,gotoPage,previousPage,nextPage" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
        <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('SO Number') }}</th>
                        <th class="py-2 px-3">{{ __('Customer') }}</th>
                        <th class="py-2 px-3">{{ __('Order Date') }}</th>
                        <th class="py-2 px-3">{{ __('Expected Delivery') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Delivered') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Total') }}</th>
                        <th class="py-2 px-3">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->orders as $order)
                        @php($ordered = $order->items->reduce(fn ($c, $i) => bcadd($c, (string) $i->quantity_ordered, 2), '0'))
                        @php($delivered = $order->items->reduce(fn ($c, $i) => bcadd($c, (string) $i->quantity_delivered, 2), '0'))
                        @php($late = $order->expected_delivery_date && $order->expected_delivery_date->isPast() && ! $order->expected_delivery_date->isToday() && $order->status->isDeliverable())
                        <tr wire:key="so-{{ $order->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                            <td class="py-2 px-3 whitespace-nowrap">
                                <a href="{{ route('sales.orders.show', $order) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $order->so_number }}</a>
                            </td>
                            <td class="py-2 px-3 text-zinc-800 dark:text-zinc-200">{{ $order->customer->customer_name }}</td>
                            <td class="py-2 px-3 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $order->order_date->format('d M Y') }}</td>
                            <td class="py-2 px-3 font-data tabular-nums whitespace-nowrap {{ $late ? 'text-red-600 dark:text-red-400' : 'text-zinc-600 dark:text-zinc-400' }}">{{ $order->expected_delivery_date?->format('d M Y') ?? '—' }}@if ($late) · {{ __('late') }}@endif</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300 whitespace-nowrap">{{ rtrim(rtrim($delivered, '0'), '.') ?: '0' }} / {{ rtrim(rtrim($ordered, '0'), '.') }}</td>
                            <td class="py-2 px-3 text-right font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatRupiah((string) $order->total_amount) }}</td>
                            <td class="py-2 px-3">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap {{ $this->statusBadgeClasses($order->status) }}">{{ $order->status->label() }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No sales orders yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $this->orders->links() }}</div>
    </div>
</section>
