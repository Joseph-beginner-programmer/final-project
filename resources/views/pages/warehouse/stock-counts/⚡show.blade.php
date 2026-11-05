<?php

use App\Actions\Warehouse\CancelStockCountAction;
use App\Actions\Warehouse\PostStockCountAction;
use App\Actions\Warehouse\SaveStockCountDraftAction;
use App\Enums\DocumentStatus;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use App\Exceptions\InvalidStockCountException;
use App\Exceptions\StockCountNotEditableException;
use App\Models\StockCount;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Stock Count')] class extends Component {
    public StockCount $stockCount;

    public array $counted = [];

    public function mount(): void
    {
        Gate::authorize('view', $this->stockCount);
        $this->loadRelations();

        foreach ($this->stockCount->items as $item) {
            $this->counted[$item->id] = $item->counted_quantity === null ? '' : $this->formatQuantity((string) $item->counted_quantity);
        }
    }

    protected function loadRelations(): void
    {
        $this->stockCount->load(['items.product', 'items.stockMovements.inventoryLot', 'createdBy', 'postedBy']);
    }

    protected function rules(): array
    {
        return ['counted.*' => ['nullable', 'numeric', 'min:0']];
    }

    protected function validationAttributes(): array
    {
        return ['counted.*' => __('Counted quantity')];
    }

    public function journalPlan(): ?array
    {
        if ($this->stockCount->status !== DocumentStatus::Draft) {
            return null;
        }

        try {
            return app(PostStockCountAction::class)->preview($this->stockCount);
        } catch (\Throwable) {
            return null;
        }
    }

    public function saveDraft(): void
    {
        Gate::authorize('update', $this->stockCount);

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Flux::toast(variant: 'danger', text: __('Please fix the highlighted fields before submitting.'));
            throw $e;
        }

        try {
            app(SaveStockCountDraftAction::class)->handle($this->stockCount, $this->counted);
        } catch (StockCountNotEditableException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->loadRelations();
        Flux::toast(variant: 'success', text: __('Draft saved.'));
    }

    public function post(): void
    {
        Gate::authorize('post', $this->stockCount);

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->modal('post-count')->close();
            Flux::toast(variant: 'danger', text: __('Please fix the highlighted fields before submitting.'));
            throw $e;
        }

        try {
            app(SaveStockCountDraftAction::class)->handle($this->stockCount, $this->counted);
            app(PostStockCountAction::class)->handle($this->stockCount, Auth::id());
        } catch (InvalidStockCountException|StockCountNotEditableException|InvalidDocumentStatusTransitionException $e) {
            $this->modal('post-count')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->stockCount->refresh();
        $this->loadRelations();
        $this->modal('post-count')->close();
        Flux::toast(variant: 'success', text: __('Stock count posted.'));
    }

    public function cancel(): void
    {
        Gate::authorize('cancel', $this->stockCount);

        try {
            app(CancelStockCountAction::class)->handle($this->stockCount);
        } catch (StockCountNotEditableException|InvalidDocumentStatusTransitionException $e) {
            $this->modal('cancel-count')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->stockCount->refresh();
        $this->modal('cancel-count')->close();
        Flux::toast(variant: 'success', text: __('Stock count cancelled.'));
    }

    public function formatQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    public function formatSigned(string $value): string
    {
        return (bccomp($value, '0', 2) > 0 ? '+' : '').$this->formatQuantity($value);
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

@php($count = $stockCount)
@php($draft = $count->status === DocumentStatus::Draft)
@php($posted = $count->status === DocumentStatus::Posted)
@php($showCost = auth()->user()->can('costs.view'))
@php($label = 'text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')
@php($countedLines = collect($counted)->filter(fn ($v) => $v !== '')->count())
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('warehouse.stock-counts.list')" wire:navigate>{{ __('Stock Count') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $count->count_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-data text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $count->count_number }}</h1>
                <span wire:key="status-{{ $count->status->value }}" wire:transition class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $this->documentBadgeClasses($count->status) }}">{{ $count->status->label() }}</span>
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                {{ __('Count date') }} <span class="font-data">{{ $count->count_date->translatedFormat('j M Y') }}</span>
                @if ($draft)
                    · {{ __(':counted of :total products counted', ['counted' => $countedLines, 'total' => $count->items->count()]) }}
                @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" :href="route('warehouse.stock-counts.list')" wire:navigate class="active:scale-[0.95]">{{ __('Back to List') }}</flux:button>
            @if ($draft)
                <flux:button variant="filled" icon="arrow-down-tray" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft" class="active:scale-[0.95]">{{ __('Save Draft') }}</flux:button>
                @can('cancel', $count)
                    <flux:modal.trigger name="cancel-count">
                        <flux:button variant="ghost" icon="x-mark" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button>
                    </flux:modal.trigger>
                @endcan
                @can('post', $count)
                    <flux:modal.trigger name="post-count">
                        <flux:button variant="primary" icon="clipboard-document-check" class="active:scale-[0.95]">{{ __('Post') }}</flux:button>
                    </flux:modal.trigger>
                @endcan
            @endif
        </div>
    </div>

    <div class="mt-6 grid grid-cols-2 {{ $showCost && $posted ? 'sm:grid-cols-4' : 'sm:grid-cols-3' }} rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y sm:divide-y-0 sm:divide-x divide-zinc-100 dark:divide-white/5 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="px-4 py-3">
            <p class="{{ $label }}">{{ __('Created by') }}</p>
            <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-200">{{ $count->createdBy->name }}</p>
            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $count->created_at->format('d M Y H:i') }}</p>
        </div>
        <div class="px-4 py-3">
            <p class="{{ $label }}">{{ __('Posted by') }}</p>
            <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-200">{{ $count->postedBy?->name ?? '—' }}</p>
            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $count->posted_at?->format('d M Y H:i') }}</p>
        </div>
        <div class="px-4 py-3">
            <p class="{{ $label }}">{{ __('Products on this sheet') }}</p>
            <p class="mt-1 font-data text-sm text-zinc-800 dark:text-zinc-200">{{ $count->items->count() }}</p>
        </div>
        @if ($showCost && $posted)
            <div class="px-4 py-3">
                <p class="{{ $label }}">{{ __('Net adjustment') }}</p>
                <p class="mt-1 font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($count->netAdjustmentValue()) }}</p>
                <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('+ surplus, − shortage') }}</p>
            </div>
        @endif
    </div>

    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        @foreach ($count->items->groupBy(fn ($item) => $item->product->type->value) as $typeValue => $items)
            @php($type = $items->first()->product->type)
            <h2 class="mt-6 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3 first:mt-0">{{ $type->label() }}</h2>
            <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
                <table class="w-full text-xs border-collapse">
                    <thead>
                        <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                            <th class="py-2 px-3">{{ __('Product') }}</th>
                            <th class="py-2 px-3 text-right">{{ __('System Qty') }}</th>
                            <th class="py-2 px-3 text-right">{{ $draft ? __('Counted Qty') : __('Counted') }}</th>
                            <th class="py-2 px-3 text-right">{{ __('Variance') }}</th>
                            @if ($showCost && ! $draft)
                                <th class="py-2 px-3 text-right">{{ __('Adjustment Value') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            @php($unit = $item->product->unit_of_measure)
                            @php($variance = $draft ? null : (string) $item->variance_quantity)
                            <tr wire:key="sci-{{ $item->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                                <td class="py-2 px-3">
                                    <p class="text-zinc-900 dark:text-white">{{ $item->product->product_name }}</p>
                                    <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $item->product->product_code }}</p>
                                </td>
                                <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $this->formatQuantity((string) $item->system_quantity) }} {{ $unit }}</td>
                                <td class="py-2 px-3 text-right whitespace-nowrap">
                                    @if ($draft)
                                        <flux:input size="sm" type="number" step="any" wire:model.live.debounce.500ms="counted.{{ $item->id }}" class="w-28 text-right" :aria-label="__('Counted quantity').' — '.$item->product->product_name" />
                                    @else
                                        <span class="font-data tabular-nums text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $item->counted_quantity) }} {{ $unit }}</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap">
                                    @if ($draft)
                                        <span class="text-zinc-400 dark:text-zinc-500">—</span>
                                    @else
                                        <span class="{{ bccomp($variance, '0', 2) === 0 ? 'text-zinc-500 dark:text-zinc-400' : (bccomp($variance, '0', 2) < 0 ? 'text-red-600 dark:text-red-400' : 'text-amber-700 dark:text-amber-400') }}">{{ $this->formatSigned($variance) }} {{ $unit }}</span>
                                    @endif
                                </td>
                                @if ($showCost && ! $draft)
                                    <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-900 dark:text-white">{{ bccomp((string) $item->adjustment_value, '0', 2) === 0 ? '—' : $this->formatRupiah((string) $item->adjustment_value) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    @can('post', $count)
        <flux:modal name="post-count" focusable class="max-w-xl">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Post this stock count?') }}</flux:heading>
                    <flux:subheading>{{ __('Stock moves to match what was physically counted. A posted stock count can no longer be changed.') }}</flux:subheading>
                </div>
                @if ($countedLines < $count->items->count())
                    <p class="flex items-start gap-1.5 rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                        <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-3.5 shrink-0" />
                        {{ __('Every product needs a counted quantity before this can be posted. :missing left blank.', ['missing' => $count->items->count() - $countedLines]) }}
                    </p>
                @else
                    <x-journal-confirm :plan="$this->journalPlan()" :hide-amounts="! \Illuminate\Support\Facades\Gate::allows('costs.view')" />
                @endif
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Back') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="post" wire:loading.attr="disabled" wire:target="post" :disabled="$countedLines < $count->items->count()" class="active:scale-[0.95]">{{ __('Post') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan

    @can('cancel', $count)
        <flux:modal name="cancel-count" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Cancel this stock count?') }}</flux:heading>
                    <flux:subheading>{{ __('Nothing has moved yet. All counted quantities entered so far are discarded.') }}</flux:subheading>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Keep draft') }}</flux:button></flux:modal.close>
                    <flux:button variant="danger" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan
</section>
