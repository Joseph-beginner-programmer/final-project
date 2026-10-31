<?php

use App\Actions\Warehouse\SaveMaterialReturnDraftAction;
use App\Exceptions\InvalidMaterialReturnException;
use App\Exceptions\MaterialReturnNotEditableException;
use App\Models\MaterialReturn;
use App\Models\ProductionResult;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Material Return')] class extends Component {
    #[Locked]
    public int $productionResultId;

    #[Locked]
    public ?int $materialReturnId = null;

    public array $received = [];

    public function mount(?ProductionResult $productionResult = null, ?MaterialReturn $materialReturn = null): void
    {
        if ($materialReturn?->exists) {
            Gate::authorize('update', $materialReturn);
            $productionResult = $materialReturn->productionResult;
            $this->materialReturnId = $materialReturn->id;
        } else {
            Gate::authorize('create', MaterialReturn::class);

            $draft = $productionResult->materialReturns()->where('status', 'draft')->first();
            if ($draft) {
                $this->redirectRoute('warehouse.inbound.material-returns.show', $draft, navigate: true);

                return;
            }
        }

        $this->productionResultId = $productionResult->id;

        $saved = $materialReturn?->exists
            ? $materialReturn->items->groupBy('product_id')->map(fn ($rows) => $rows->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->quantity, 2), '0'))
            : null;

        foreach ($this->rows as $row) {
            $quantity = $saved ? ($saved[$row['product']->id] ?? '0') : $row['expected'];
            $this->received[$row['product']->id] = ['quantity' => $this->formatQuantity($quantity)];
        }
    }

    #[Computed]
    public function result(): ProductionResult
    {
        return ProductionResult::with('workOrder.product')->findOrFail($this->productionResultId);
    }

    #[Computed]
    public function rows(): Collection
    {
        return $this->result->workOrder->postedIssueItems()
            ->groupBy('product_id')
            ->map(function ($lines) {
                $issued = $lines->reduce(fn (string $carry, $line) => bcadd($carry, (string) $line->quantity, 2), '0');
                $used = $lines->reduce(fn (string $carry, $line) => bcadd($carry, (string) $line->quantity_consumed, 2), '0');

                return [
                    'product' => $lines->first()->product,
                    'issued' => $issued,
                    'used' => $used,
                    'expected' => bcsub($issued, $used, 2),
                ];
            })
            ->filter(fn ($row) => bccomp($row['expected'], '0', 2) > 0)
            ->values();
    }

    public function difference(string $expected, mixed $received): ?string
    {
        return is_numeric($received) ? bcsub((string) $received, $expected, 2) : null;
    }

    public function formatQuantity(string $quantity): string
    {
        if ($quantity === '' || ! str_contains($quantity, '.')) {
            return $quantity;
        }

        return rtrim(rtrim($quantity, '0'), '.');
    }

    protected function rules(): array
    {
        return [
            'received.*.quantity' => ['required', 'numeric', 'min:0'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'received.*.quantity' => __('Received'),
        ];
    }

    public function save(): void
    {
        $return = $this->materialReturnId ? MaterialReturn::findOrFail($this->materialReturnId) : null;
        $return ? Gate::authorize('update', $return) : Gate::authorize('create', MaterialReturn::class);

        try {
            $this->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            Flux::toast(variant: 'danger', text: __('Please fix the highlighted fields before submitting.'));
            throw $e;
        }

        $returned = collect($this->received)->mapWithKeys(fn ($row, $productId) => [(int) $productId => (string) $row['quantity']])->all();

        try {
            $return = app(SaveMaterialReturnDraftAction::class)->handle($this->result, $returned, Auth::id(), $return);
        } catch (InvalidMaterialReturnException|MaterialReturnNotEditableException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Draft :number saved. Stock comes back only when it is posted.', ['number' => $return->return_number]));
        $this->redirectRoute('warehouse.inbound.material-returns.show', $return, navigate: true);
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

@php($result = $this->result)
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('warehouse.inbound.material-returns.list')" wire:navigate>{{ __('Material Return') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $materialReturnId ? __('Edit') : __('Create') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ $materialReturnId ? __('Edit Material Return') : __('Receive Material Return') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                {{ __('From') }} <span class="font-data text-accent">{{ $result->workOrder->wo_number }}</span> · {{ $result->workOrder->product->product_name }}
                · {{ __('Production finished') }} <span class="font-data tabular-nums">{{ $result->production_date->format('d M Y') }}</span>
            </p>
        </div>

        <flux:button variant="primary" icon="document-check" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">
            {{ __('Save Draft') }}
        </flux:button>
    </div>

    <div class="mt-6 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">{{ __('Leftover material') }}</h2>

        @php($grid = 'grid grid-cols-3 md:grid-cols-[minmax(0,1fr)_6.5rem_6.5rem_7rem_9rem_6.5rem] gap-x-3 gap-y-2 items-start')
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <div class="{{ $grid }} hidden md:grid px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10 rounded-t-xl">
                <span>{{ __('Material') }}</span>
                <span class="text-right">{{ __('Issued') }}</span>
                <span class="text-right">{{ __('Used') }}</span>
                <span class="text-right">{{ __('Expected back') }}</span>
                <span class="text-right">{{ __('Received') }}</span>
                <span class="text-right">{{ __('Difference') }}</span>
            </div>

            @foreach ($this->rows as $row)
                @php($p = $row['product'])
                @php($diff = $this->difference($row['expected'], $received[$p->id]['quantity'] ?? null))
                <div wire:key="ret-{{ $p->id }}" class="{{ $grid }} px-4 py-3 border-b border-zinc-100 dark:border-white/5 last:border-0">
                    <div class="col-span-3 md:col-span-1 min-w-0">
                        <p class="text-sm text-zinc-900 dark:text-white">{{ $p->product_name }}</p>
                        <p class="font-data text-[11px] text-accent">{{ $p->product_code }}</p>
                    </div>

                    @foreach ([__('Issued') => $row['issued'], __('Used') => $row['used'], __('Expected back') => $row['expected']] as $label => $value)
                        <div class="md:text-right text-xs">
                            <p class="md:hidden text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                            <p class="md:pt-2 font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity($value) }} {{ $p->unit_of_measure }}</p>
                        </div>
                    @endforeach

                    <div class="col-span-2 md:col-span-1">
                        <p class="md:hidden mb-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Received') }}</p>
                        <flux:input.group>
                            <flux:input size="sm" type="text" inputmode="decimal" pattern="[0-9]*\.?[0-9]*" placeholder="0" input:class="text-right font-data tabular-nums" :loading="false"
                                wire:model.live.debounce.400ms="received.{{ $p->id }}.quantity" :aria-label="__('Received').' — '.$p->product_name" />
                            <flux:input.group.suffix class="px-2 text-xs">{{ $p->unit_of_measure }}</flux:input.group.suffix>
                        </flux:input.group>
                        @error("received.{$p->id}.quantity") <flux:error class="mt-1" :message="$message" /> @enderror
                    </div>

                    <div class="md:text-right text-xs">
                        <p class="md:hidden text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Difference') }}</p>
                        <p class="md:pt-2 font-data tabular-nums {{ $diff === null || bccomp($diff, '0', 2) === 0 ? 'text-zinc-500 dark:text-zinc-400' : (bccomp($diff, '0', 2) < 0 ? 'text-red-600 dark:text-red-400' : 'text-amber-700 dark:text-amber-400') }}">
                            {{ $diff === null ? '—' : (bccomp($diff, '0', 2) > 0 ? '+' : '').$this->formatQuantity($diff).' '.$p->unit_of_measure }}
                        </p>
                    </div>
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Enter what actually arrived back. A difference from the expected leftover is recorded as a material variance.') }}</p>
    </div>
</section>
