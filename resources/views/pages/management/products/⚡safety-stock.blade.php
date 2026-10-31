<?php

use App\Actions\MasterData\ApplySafetyStockSuggestionAction;
use App\Models\Product;
use App\Support\SafetyStockCalculator;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Safety Stock & Reorder Point')] class extends Component {
    public function mount(): void
    {
        Gate::authorize('viewAny', Product::class);
    }

    #[Computed]
    public function calculator(): SafetyStockCalculator
    {
        $end = now()->endOfDay();

        return new SafetyStockCalculator($end->copy()->subMonths(6)->startOfDay(), $end);
    }

    #[Computed]
    public function rows(): Collection
    {
        return $this->calculator->rows();
    }

    public function apply(int $productId): void
    {
        $row = $this->rows->firstWhere('product.id', $productId);
        if (! $row || ! $row['sufficient_data']) {
            return;
        }

        Gate::authorize('update', $row['product']);
        app(ApplySafetyStockSuggestionAction::class)->handle($row['product'], $row['suggested_safety_stock'], $row['suggested_reorder_point']);
        unset($this->rows);
        Flux::toast(variant: 'success', text: __(':product updated.', ['product' => $row['product']->product_name]));
    }

    public function applyAll(): void
    {
        foreach ($this->rows->where('sufficient_data', true) as $row) {
            Gate::authorize('update', $row['product']);
            app(ApplySafetyStockSuggestionAction::class)->handle($row['product'], $row['suggested_safety_stock'], $row['suggested_reorder_point']);
        }
        unset($this->rows);
        Flux::toast(variant: 'success', text: __('Suggestions applied to every material with enough data.'));
    }

    public function formatQty(string|float $value): string
    {
        $formatted = number_format((float) $value, 2, ',', '.');

        return str_ends_with($formatted, ',00') ? substr($formatted, 0, -3) : rtrim($formatted, '0');
    }

    public function formatDays(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1, ',', '.');
    }
}; ?>

@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 whitespace-nowrap')
@php($num = 'py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap')
@php($ink = 'text-zinc-800 dark:text-zinc-200')
@php($applicable = $this->rows->where('sufficient_data', true)->count())

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('management.products.list')" wire:navigate>{{ __('Products') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Safety Stock & Reorder Point') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Safety Stock & Reorder Point') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Calculated from the last 6 months of material usage and actual supplier delivery times.') }}</p>
        </div>
        @if ($applicable > 0)
            <flux:button variant="primary" icon="check" wire:click="applyAll" wire:confirm="{{ __('Apply the suggested values to all :count materials with enough data?', ['count' => $applicable]) }}" class="active:scale-[0.95]">
                {{ __('Apply all (:count)', ['count' => $applicable]) }}
            </flux:button>
        @endif
    </div>

    <div class="mt-6 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
        <table class="w-full min-w-[80rem] text-xs border-collapse">
            <thead>
                <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                    <th class="{{ $th }} min-w-[16rem]">{{ __('Material') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Avg usage/day') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Max usage/day') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Avg lead time') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Max lead time') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Current SS') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Current ROP') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Suggested SS') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Suggested ROP') }}</th>
                    <th class="{{ $th }}"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->rows as $row)
                    @php($p = $row['product'])
                    @php($changed = $row['sufficient_data'] && (bccomp($row['suggested_safety_stock'], $row['current_safety_stock'], 2) !== 0 || bccomp($row['suggested_reorder_point'], $row['current_reorder_point'], 2) !== 0))
                    <tr wire:key="ss-{{ $p->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03]">
                        <td class="py-2.5 px-3">
                            <span class="text-zinc-900 dark:text-white">{{ $p->product_name }}</span>
                            <p class="text-[11px] text-zinc-500 dark:text-zinc-400 font-data">{{ $p->product_code }} · {{ $p->unit_of_measure }}</p>
                        </td>
                        <td class="{{ $num }} {{ $ink }}">{{ $this->formatQty($row['avg_usage']) }}</td>
                        <td class="{{ $num }} {{ $ink }}">{{ $this->formatQty($row['max_usage']) }}</td>
                        <td class="{{ $num }} {{ $ink }}">{{ $this->formatDays($row['avg_lead_time']) }}</td>
                        <td class="{{ $num }} {{ $ink }}">{{ $row['max_lead_time'] ?? '—' }}</td>
                        <td class="{{ $num }} text-zinc-500 dark:text-zinc-400">{{ $this->formatQty($row['current_safety_stock']) }}</td>
                        <td class="{{ $num }} text-zinc-500 dark:text-zinc-400">{{ $this->formatQty($row['current_reorder_point']) }}</td>
                        @if ($row['sufficient_data'])
                            <td class="{{ $num }} font-semibold {{ $changed ? 'text-accent' : $ink }}">{{ $this->formatQty($row['suggested_safety_stock']) }}</td>
                            <td class="{{ $num }} font-semibold {{ $changed ? 'text-accent' : $ink }}">{{ $this->formatQty($row['suggested_reorder_point']) }}</td>
                            <td class="py-2.5 px-3 text-right">
                                @if ($changed)
                                    <flux:button size="xs" variant="filled" wire:click="apply({{ $p->id }})" class="active:scale-[0.95]">{{ __('Apply') }}</flux:button>
                                @else
                                    <span class="text-[11px] text-zinc-400">{{ __('Up to date') }}</span>
                                @endif
                            </td>
                        @else
                            <td class="{{ $num }} text-zinc-400" colspan="2">{{ __('Not enough data') }}</td>
                            <td></td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="10" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No raw materials found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Applying a suggestion only changes this product\'s Safety stock / Reorder point fields on the Products page — they stay editable afterwards.') }}</p>
</section>
