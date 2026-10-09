<?php

use App\Enums\DocumentStatus;
use App\Enums\WorkOrderStatus;
use App\Models\ProductionResult;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Production Results')] class extends Component {
    use WithPagination;

    #[Url]
    public string $tab = 'work-orders';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', ProductionResult::class);
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

    /**
     * Work orders on the floor whose result hasn't been posted yet (none, or a draft).
     */
    #[Computed]
    public function workOrders(): Collection
    {
        $term = $this->term();

        return WorkOrder::query()
            ->where('status', WorkOrderStatus::InProgress->value)
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('wo_number', 'like', "%{$term}%")
                ->orWhereHas('product', fn (Builder $q) => $q->where('product_name', 'like', "%{$term}%"))))
            ->with(['product', 'productionResult'])
            ->orderBy('planned_end_date')
            ->get();
    }

    #[Computed]
    public function results(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $term = $this->term();

        return ProductionResult::query()
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('result_number', 'like', "%{$term}%")
                ->orWhereHas('workOrder', fn (Builder $q) => $q->where('wo_number', 'like', "%{$term}%"))))
            ->with(['workOrder.product'])
            ->orderByDesc('id')
            ->paginate(15);
    }

    public function formatQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    public function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    public function documentBadgeClasses(DocumentStatus $status): string
    {
        return match ($status) {
            DocumentStatus::Draft => 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:border-white/10',
            DocumentStatus::Posted => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
            DocumentStatus::Cancelled => 'bg-zinc-100 text-zinc-500 border-zinc-200 line-through dark:bg-white/5 dark:text-zinc-500 dark:border-white/10',
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
        <flux:breadcrumbs.item :href="route('production.dashboard')" wire:navigate>{{ __('Production') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Production Results') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 motion-safe:animate-fade-slide-up">
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
            {{ __('Production Results') }}
        </h1>
        <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
    </div>

    {{-- Tabs --}}
    <div class="mt-6 flex items-center gap-1.5 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        @foreach (['work-orders' => __('Work orders awaiting a result'), 'results' => __('Result documents')] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                    {{ $tab === $key ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
                {{ $label }}
                @if ($key === 'work-orders')
                    <span class="font-data tabular-nums {{ $tab === $key ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->workOrders->count() }}</span>
                @endif
            </button>
        @endforeach
    </div>
    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    <div class="mt-4 motion-safe:animate-fade-slide-up" style="animation-delay: 60ms;">
        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search"
            :placeholder="$tab === 'work-orders' ? __('Search work order number or product...') : __('Search result or work order number...')" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,tab" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 80ms;">
        @if ($tab === 'work-orders')
            <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5">
                @forelse ($this->workOrders as $workOrder)
                    @php($draft = $workOrder->productionResult)
                    @php($late = $workOrder->planned_end_date->isPast())
                    <div wire:key="wo-{{ $workOrder->id }}" class="flex flex-col sm:flex-row sm:items-center gap-3 px-4 py-3.5 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('production.work-orders.show', $workOrder) }}" wire:navigate class="font-data text-sm font-medium text-accent hover:underline">{{ $workOrder->wo_number }}</a>
                                @if ($draft)
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->documentBadgeClasses($draft->status) }}">{{ __('Draft') }} {{ $draft->result_number }}</span>
                                @endif
                            </div>
                            <p class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">{{ $workOrder->product->product_name }}</p>
                        </div>

                        <div class="sm:w-56 grid grid-cols-2 gap-3 text-xs">
                            <div>
                                <p class="text-zinc-500 dark:text-zinc-400">{{ __('Target') }}</p>
                                <p class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatQuantity((string) $workOrder->quantity_target) }} {{ $workOrder->product->unit_of_measure }}</p>
                            </div>
                            <div>
                                <p class="text-zinc-500 dark:text-zinc-400">{{ __('Planned End') }}</p>
                                <p class="font-data tabular-nums {{ $late ? 'text-red-600 dark:text-red-400' : 'text-zinc-800 dark:text-zinc-200' }}">{{ $workOrder->planned_end_date->format('d M Y') }}</p>
                            </div>
                        </div>

                        <div class="sm:w-44 flex sm:justify-end">
                            @if ($draft)
                                <flux:button size="sm" variant="filled" icon="pencil-square" :href="route('production.results.show', $draft)" wire:navigate class="active:scale-[0.95]">
                                    {{ __('Open draft') }}
                                </flux:button>
                            @else
                                @can('create', ProductionResult::class)
                                    <flux:button size="sm" variant="primary" icon="clipboard-document-check" :href="route('production.results.create', $workOrder)" wire:navigate class="active:scale-[0.95]">
                                        {{ __('Record result') }}
                                    </flux:button>
                                @endcan
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No work orders in progress right now.') }}</p>
                @endforelse
            </div>
        @else
            <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
                <table class="w-full text-xs border-collapse">
                    <thead>
                        <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                            <th class="py-2 px-3">{{ __('Result Number') }}</th>
                            <th class="py-2 px-3">{{ __('Work Order') }}</th>
                            <th class="py-2 px-3">{{ __('Completion date') }}</th>
                            <th class="py-2 px-3 text-right">{{ __('Good') }}</th>
                            <th class="py-2 px-3 text-right">{{ __('Reject') }}</th>
                            <th class="py-2 px-3 text-right">{{ __('Unit cost') }}</th>
                            <th class="py-2 px-3">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->results as $result)
                            @php($unit = $result->workOrder->product->unit_of_measure)
                            <tr wire:key="res-{{ $result->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                                <td class="py-2 px-3 whitespace-nowrap">
                                    <a href="{{ route('production.results.show', $result) }}" wire:navigate class="font-data text-accent hover:underline">{{ $result->result_number }}</a>
                                </td>
                                <td class="py-2 px-3">
                                    <p class="font-data text-zinc-700 dark:text-zinc-300">{{ $result->workOrder->wo_number }}</p>
                                    <p class="text-zinc-500 dark:text-zinc-400">{{ $result->workOrder->product->product_name }}</p>
                                </td>
                                <td class="py-2 px-3 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $result->production_date->format('d M Y') }}</td>
                                <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatQuantity((string) $result->quantity_good) }} {{ $unit }}</td>
                                <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $this->formatQuantity((string) $result->quantity_reject) }} {{ $unit }}</td>
                                <td class="py-2 px-3 text-right font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $result->unit_cost !== null ? $this->formatRupiah((string) $result->unit_cost) : '—' }}</td>
                                <td class="py-2 px-3">
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->documentBadgeClasses($result->status) }}">{{ $result->status->label() }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No production results yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $this->results->links() }}</div>
        @endif
    </div>
</section>
