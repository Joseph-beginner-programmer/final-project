<?php

use App\Actions\Warehouse\CreateStockCountAction;
use App\Enums\DocumentStatus;
use App\Exceptions\InvalidStockCountException;
use App\Models\StockCount;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Stock Count')] class extends Component {
    public function mount(): void
    {
        Gate::authorize('viewAny', StockCount::class);
    }

    #[Computed]
    public function open(): ?StockCount
    {
        return StockCount::where('status', DocumentStatus::Draft->value)->with('items')->first();
    }

    #[Computed]
    public function history(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return StockCount::query()
            ->where('status', '!=', DocumentStatus::Draft->value)
            ->with('createdBy', 'postedBy')
            ->orderByDesc('id')
            ->paginate(15);
    }

    public function start(): void
    {
        Gate::authorize('create', StockCount::class);

        try {
            $count = app(CreateStockCountAction::class)->handle(Auth::id());
        } catch (InvalidStockCountException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->redirectRoute('warehouse.stock-counts.show', $count, navigate: true);
    }

    public function formatRupiah(string $amount): string
    {
        return ((float) $amount < 0 ? '−Rp ' : 'Rp ').number_format(abs((float) $amount), 0, ',', '.');
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

@php($showCost = auth()->user()->can('costs.view'))
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item>{{ __('Stock Count') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Stock Count') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Reconcile physical stock against system quantities and post the difference.') }}</p>
        </div>
        @if (! $this->open)
            <flux:button variant="primary" icon="clipboard-document-check" wire:click="start" wire:loading.attr="disabled" wire:target="start" class="active:scale-[0.95]">
                {{ __('Start new stock count') }}
            </flux:button>
        @endif
    </div>

    @if ($this->open)
        @php($countedLines = $this->open->items->filter(fn ($i) => $i->counted_quantity !== null)->count())
        @php($totalLines = $this->open->items->count())
        <div class="mt-6 rounded-xl border border-zinc-200 dark:border-white/10 bg-gradient-to-br from-accent/[0.07] to-transparent dark:from-accent/10 p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Open stock count') }}</h2>
                    <p class="mt-1.5 font-data text-lg font-semibold text-zinc-900 dark:text-white">{{ $this->open->count_number }}</p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('Started :date', ['date' => $this->open->count_date->translatedFormat('j M Y')]) }} · {{ __(':counted of :total products counted', ['counted' => $countedLines, 'total' => $totalLines]) }}
                    </p>
                </div>
                <flux:button variant="filled" icon="pencil-square" :href="route('warehouse.stock-counts.show', $this->open)" wire:navigate class="active:scale-[0.95]">
                    {{ __('Continue counting') }}
                </flux:button>
            </div>
        </div>
    @endif

    <div class="mt-6">
        <h2 class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('History') }}</h2>
        <div class="mt-3 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Count Number') }}</th>
                        <th class="py-2 px-3">{{ __('Count Date') }}</th>
                        <th class="py-2 px-3">{{ __('Posted By') }}</th>
                        @if ($showCost)
                            <th class="py-2 px-3 text-right">{{ __('Net Adjustment') }}</th>
                        @endif
                        <th class="py-2 px-3">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->history as $count)
                        <tr wire:key="sc-{{ $count->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                            <td class="py-2 px-3 whitespace-nowrap">
                                <a href="{{ route('warehouse.stock-counts.show', $count) }}" wire:navigate class="font-data text-accent hover:underline">{{ $count->count_number }}</a>
                            </td>
                            <td class="py-2 px-3 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $count->count_date->format('d M Y') }}</td>
                            <td class="py-2 px-3 text-zinc-700 dark:text-zinc-300">{{ $count->postedBy?->name ?? '—' }}</td>
                            @if ($showCost)
                                <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-900 dark:text-white">{{ $count->status === DocumentStatus::Posted ? $this->formatRupiah($count->netAdjustmentValue()) : '—' }}</td>
                            @endif
                            <td class="py-2 px-3">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->documentBadgeClasses($count->status) }}">{{ $count->status->label() }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $showCost ? 5 : 4 }}" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No stock counts yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $this->history->links() }}</div>
    </div>
</section>
