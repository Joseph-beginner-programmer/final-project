<?php

use App\Enums\DocumentStatus;
use App\Models\ProductionReceipt;
use App\Models\ProductionResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Production Receipt')] class extends Component {
    use WithPagination;

    #[Url]
    public string $tab = 'waiting';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', ProductionReceipt::class);
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

        return ProductionResult::query()
            ->where('status', DocumentStatus::Posted->value)
            ->whereDoesntHave('productionReceipts', fn (Builder $q) => $q->where('status', DocumentStatus::Posted->value))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('result_number', 'like', "%{$term}%")
                ->orWhereHas('workOrder', fn (Builder $q) => $q->where('wo_number', 'like', "%{$term}%")
                    ->orWhereHas('product', fn (Builder $q) => $q->where('product_name', 'like', "%{$term}%")))))
            ->with(['workOrder.product', 'productionReceipts' => fn ($q) => $q->where('status', DocumentStatus::Draft->value)])
            ->orderBy('posted_at')
            ->get();
    }

    #[Computed]
    public function receipts(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $term = $this->term();

        return ProductionReceipt::query()
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('receipt_number', 'like', "%{$term}%")
                ->orWhereHas('productionResult.workOrder', fn (Builder $q) => $q->where('wo_number', 'like', "%{$term}%"))))
            ->with(['product', 'productionResult.workOrder', 'lot'])
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
        <flux:breadcrumbs.item :href="route('warehouse.dashboard')" wire:navigate>{{ __('Warehouse') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Production Receipt') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 motion-safe:animate-fade-slide-up">
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
            {{ __('Production Receipt') }}
        </h1>
        <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
    </div>

    <div class="mt-6 flex items-center gap-1.5 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        @foreach (['waiting' => __('Output waiting to be received'), 'documents' => __('Receipt documents')] as $key => $label)
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
            :placeholder="$tab === 'waiting' ? __('Search work order, result or product...') : __('Search receipt or WO number...')" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,tab" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
        @if ($tab === 'waiting')
            <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
                @forelse ($this->waiting as $result)
                    @php($draft = $result->productionReceipts->first())
                    @php($product = $result->workOrder->product)
                    <div wire:key="wait-{{ $result->id }}" class="flex flex-col sm:flex-row sm:items-center gap-3 px-4 py-3.5 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-data text-sm font-medium text-accent">{{ $result->workOrder->wo_number }}</span>
                                @if ($draft)
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->documentBadgeClasses($draft->status) }}">{{ __('Draft') }} {{ $draft->receipt_number }}</span>
                                @endif
                            </div>
                            <p class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">{{ $product->product_name }}</p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ __('Production finished') }} <span class="font-data tabular-nums">{{ $result->production_date->format('d M Y') }}</span> · <span class="font-data">{{ $result->result_number }}</span>
                            </p>
                        </div>

                        <div class="sm:w-40 text-xs">
                            <p class="text-zinc-500 dark:text-zinc-400">{{ __('To receive') }}</p>
                            <p class="font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $result->quantity_good) }} {{ $product->unit_of_measure }}</p>
                        </div>

                        <div class="sm:w-44 flex sm:justify-end">
                            @if ($draft)
                                <flux:button size="sm" variant="filled" icon="pencil-square" :href="route('warehouse.inbound.production-receipts.show', $draft)" wire:navigate class="active:scale-[0.95]">
                                    {{ __('Open draft') }}
                                </flux:button>
                            @else
                                <flux:button size="sm" variant="primary" icon="inbox-arrow-down" :href="route('warehouse.inbound.production-receipts.create', $result)" wire:navigate class="active:scale-[0.95]">
                                    {{ __('Receive output') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No production output is waiting to be received.') }}</p>
                @endforelse
            </div>
        @else
            <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
                <table class="w-full text-xs border-collapse">
                    <thead>
                        <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                            <th class="py-2 px-3">{{ __('Receipt Number') }}</th>
                            <th class="py-2 px-3">{{ __('Work Order') }}</th>
                            <th class="py-2 px-3 text-right">{{ __('Received') }}</th>
                            <th class="py-2 px-3">{{ __('Lot') }}</th>
                            <th class="py-2 px-3">{{ __('Received at') }}</th>
                            <th class="py-2 px-3">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->receipts as $receipt)
                            <tr wire:key="prc-{{ $receipt->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                                <td class="py-2 px-3 whitespace-nowrap">
                                    <a href="{{ route('warehouse.inbound.production-receipts.show', $receipt) }}" wire:navigate class="font-data text-accent hover:underline">{{ $receipt->receipt_number }}</a>
                                </td>
                                <td class="py-2 px-3">
                                    <p class="font-data text-zinc-700 dark:text-zinc-300">{{ $receipt->productionResult->workOrder->wo_number }}</p>
                                    <p class="text-zinc-500 dark:text-zinc-400">{{ $receipt->product->product_name }}</p>
                                </td>
                                <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">
                                    {{ $this->formatQuantity((string) $receipt->quantity) }} / {{ $this->formatQuantity((string) $receipt->quantity_expected) }} {{ $receipt->product->unit_of_measure }}
                                </td>
                                <td class="py-2 px-3 font-data text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $receipt->lot?->lot_number ?? '—' }}</td>
                                <td class="py-2 px-3 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $receipt->received_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td class="py-2 px-3">
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->documentBadgeClasses($receipt->status) }}">{{ $receipt->status->label() }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No production receipts yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $this->receipts->links() }}</div>
        @endif
    </div>
</section>
