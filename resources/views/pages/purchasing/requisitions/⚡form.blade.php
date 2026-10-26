<?php

use App\Actions\Purchasing\CreatePurchaseRequisitionAction;
use App\Exceptions\InvalidPurchaseRequisitionException;
use App\Models\Product;
use App\Support\Decimal;
use App\Support\MaterialShortage;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Purchase Request')] class extends Component {
    public array $lines = [];

    public string $neededBy = '';

    public function mount(): void
    {
        Gate::authorize('requisitions.create');
        $this->neededBy = now()->addDays(7)->toDateString();
        $this->addRow();
    }

    public function addRow(): void
    {
        $this->lines[] = ['product_id' => '', 'quantity' => ''];
    }

    public function removeRow(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        if ($this->lines === []) {
            $this->addRow();
        }
    }

    #[Computed]
    public function products(): Collection
    {
        return Product::purchasable()->where('is_active', true)->orderBy('product_code')->get()->keyBy('id');
    }

    #[Computed]
    public function productOptions(): array
    {
        return $this->products->map(fn (Product $p) => [
            'id' => (string) $p->id,
            'title' => $p->product_name,
            'subtitle' => $p->product_code,
            'meta' => __('Stock :qty :unit', ['qty' => Decimal::plain($p->current_stock), 'unit' => $p->unit_of_measure]),
            'disabled_reason' => null,
        ])->values()->all();
    }

    #[Computed]
    public function suggestions(): Collection
    {
        return MaterialShortage::suggestions();
    }

    public function addSuggestion(int $productId): void
    {
        $row = $this->suggestions->first(fn ($r) => $r['product']->id === $productId);
        if (! $row) {
            return;
        }

        $this->lines = array_values(array_filter($this->lines, fn ($l) => filled($l['product_id']) || filled($l['quantity'])));
        foreach ($this->lines as $i => $line) {
            if ((int) $line['product_id'] === $productId) {
                $this->lines[$i]['quantity'] = Decimal::plain($row['suggested']);

                return;
            }
        }

        $this->lines[] = [
            'product_id' => (string) $productId,
            'quantity' => Decimal::plain($row['suggested']),
        ];
    }

    public function addAllSuggestions(): void
    {
        foreach ($this->suggestions as $row) {
            $this->addSuggestion($row['product']->id);
        }
    }

    protected function rules(): array
    {
        return [
            'lines.*.product_id' => ['nullable', 'exists:products,id'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'neededBy' => ['required', 'date', 'after_or_equal:today'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'lines.*.product_id' => __('Material'),
            'lines.*.quantity' => __('Quantity'),
            'neededBy' => __('Needed by'),
        ];
    }

    public function submit(): void
    {
        Gate::authorize('requisitions.create');
        $this->validate();

        try {
            $requisition = app(CreatePurchaseRequisitionAction::class)->handle(
                collect($this->lines)->map(fn ($l) => ['product_id' => (int) $l['product_id'], 'quantity' => (string) $l['quantity']])->all(),
                $this->neededBy,
                Auth::id(),
            );
        } catch (InvalidPurchaseRequisitionException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Request :number sent to Purchasing.', ['number' => $requisition->requisition_number]));
        $this->redirectRoute('purchasing.requisitions.show', $requisition, navigate: true);
    }

    public function quantity(string $value): string
    {
        return number_format((float) $value, str_contains(Decimal::plain($value), '.') ? 2 : 0, ',', '.');
    }
}; ?>

@php($chosen = collect($lines)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all())

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('purchasing.requisitions.list')" wire:navigate>{{ __('Purchase Requests') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('New purchase request') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('New Purchase Request') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Ask Purchasing to buy raw materials. Purchasing chooses the supplier and price.') }}</p>
        </div>
        <flux:button variant="primary" icon="paper-airplane" wire:click="submit" wire:loading.attr="disabled" wire:target="submit" class="active:scale-[0.95]">{{ __('Send to Purchasing') }}</flux:button>
    </div>

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_22rem] gap-6 items-start">
        <div class="space-y-6 min-w-0">
            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Materials') }}</h2>
                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="addRow" class="active:scale-[0.95]">{{ __('Add row') }}</flux:button>
                </div>
                <div class="mt-3 space-y-3">
                    @foreach ($lines as $index => $line)
                        @php($product = filled($line['product_id']) ? $this->products->get((int) $line['product_id']) : null)
                        <div wire:key="prl-{{ $index }}" class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_10rem_2rem] gap-2 items-start">
                            <div>
                                <x-picker :model="'lines.'.$index.'.product_id'" :options="$this->productOptions" :selected="(string) $line['product_id']" :placeholder="__('Choose material...')" :label="__('Material')" :invalid="$errors->has('lines.'.$index.'.product_id')" />
                                @error("lines.$index.product_id") <flux:error class="mt-1" :message="$message" /> @enderror
                            </div>
                            <div>
                                <flux:input.group>
                                    <flux:input size="sm" type="text" inputmode="decimal" wire:model="lines.{{ $index }}.quantity" input:class="text-right font-data tabular-nums" placeholder="0" :loading="false" :aria-label="__('Quantity')" />
                                    <flux:input.group.suffix class="px-2 text-[11px]">{{ $product?->unit_of_measure ?? '—' }}</flux:input.group.suffix>
                                </flux:input.group>
                                @error("lines.$index.quantity") <flux:error class="mt-1" :message="$message" /> @enderror
                            </div>
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeRow({{ $index }})" :aria-label="__('Remove')" class="text-zinc-400 hover:text-red-600 active:scale-[0.95]" />
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
                <div class="max-w-[14rem]">
                    <flux:input type="date" wire:model="neededBy" :label="__('Needed by')" :min="now()->toDateString()" />
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5 lg:sticky lg:top-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Suggestions') }}</h2>
                    <p class="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Short for open work orders, or below reorder point — after stock, open POs and open requests.') }}</p>
                </div>
                @if ($this->suggestions->isNotEmpty())
                    <flux:button size="xs" variant="ghost" wire:click="addAllSuggestions" class="shrink-0">{{ __('Add all') }}</flux:button>
                @endif
            </div>
            <div class="mt-3 divide-y divide-zinc-100 dark:divide-white/5">
                @forelse ($this->suggestions as $row)
                    @php($p = $row['product'])
                    @php($added = in_array($p->id, $chosen, true))
                    <div wire:key="sug-{{ $p->id }}" class="flex items-start justify-between gap-3 py-2.5 text-xs">
                        <div class="min-w-0">
                            <p class="text-zinc-900 dark:text-white">{{ $p->product_name }} <span class="font-data text-[11px] text-zinc-500">{{ $p->product_code }}</span></p>
                            <p class="mt-0.5 font-data tabular-nums font-semibold text-red-700 dark:text-red-400">{{ $this->quantity($row['suggested']) }} {{ $p->unit_of_measure }}</p>
                            <p class="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">
                                @if ($row['work_orders'] !== [])
                                    {{ __('Needed for :wos', ['wos' => implode(', ', $row['work_orders'])]) }}
                                @endif
                                @if (bccomp($row['reorder_gap'], '0', 2) > 0)
                                    {{ $row['work_orders'] !== [] ? '·' : '' }} {{ __('below reorder point (:rp)', ['rp' => $this->quantity((string) $p->reorder_point)]) }}
                                @endif
                            </p>
                            <p class="text-[11px] text-zinc-400">{{ __('Stock :s · on order :o · requested :r', ['s' => $this->quantity($row['stock']), 'o' => $this->quantity($row['on_order']), 'r' => $this->quantity($row['requested'])]) }}</p>
                        </div>
                        <flux:button size="xs" :variant="$added ? 'ghost' : 'filled'" :icon="$added ? 'check' : 'plus'" wire:click="addSuggestion({{ $p->id }})" class="shrink-0">{{ $added ? __('Added') : __('Add') }}</flux:button>
                    </div>
                @empty
                    <p class="py-6 text-center text-xs text-zinc-500 dark:text-zinc-400">{{ __('Nothing is short right now.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</section>
