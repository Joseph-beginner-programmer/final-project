<?php

use App\Enums\PurchaseRequisitionStatus;
use App\Models\PurchaseRequisition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Purchase Requests')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = 'submitted';

    #[Url]
    public string $search = '';

    public array $selected = [];

    public function mount(): void
    {
        Gate::authorize('requisitions.view');
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function counts(): array
    {
        return PurchaseRequisition::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all();
    }

    #[Computed]
    public function requisitions(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return PurchaseRequisition::query()
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('requisition_number', 'like', "%{$term}%")
                ->orWhereHas('items.product', fn (Builder $q) => $q->where('product_name', 'like', "%{$term}%"))))
            ->with(['items.product', 'items.purchaseOrder', 'createdBy', 'purchaseOrder'])
            ->orderBy($this->status === 'submitted' ? 'needed_by' : 'id', $this->status === 'submitted' ? 'asc' : 'desc')
            ->paginate(20);
    }

    public function createPurchaseOrder(): void
    {
        Gate::authorize('requisitions.process');
        $ids = collect($this->selected)->filter()->keys()->map(fn ($id) => (int) $id)->values()->all();

        if ($ids === []) {
            \Flux\Flux::toast(variant: 'warning', text: __('Select at least one request.'));

            return;
        }

        $this->redirectRoute('purchasing.orders.create', ['requisitions' => implode(',', $ids)], navigate: true);
    }

    public function quantity(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    public function itemLine(\App\Models\PurchaseRequisitionItem $item): string
    {
        $base = $item->product->product_name.' '.$this->quantity((string) $item->quantity).' '.$item->product->unit_of_measure;

        return $item->isFulfilled() ? $base.' ('.__('on :po', ['po' => $item->purchaseOrder->po_number]).')' : $base;
    }
}; ?>

@php($canProcess = Gate::allows('requisitions.process'))
@php($selectable = $canProcess && $status === 'submitted')
@php($selectedCount = collect($selected)->filter()->count())

<section class="w-full max-w-6xl mx-auto">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Purchase Requests') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $canProcess ? __('Requests from Production. Select open requests and turn them into a purchase order.') : __('Materials you asked Purchasing to buy.') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
        @can('requisitions.create')
            <flux:button variant="filled" icon="plus" :href="route('purchasing.requisitions.create')" wire:navigate class="active:scale-[0.95]">{{ __('New request') }}</flux:button>
        @endcan
        @if ($selectable)
            <flux:button variant="primary" icon="shopping-cart" wire:click="createPurchaseOrder" :disabled="$selectedCount === 0" class="active:scale-[0.95]">
                {{ $selectedCount > 0 ? __('Create PO from :count selected', ['count' => $selectedCount]) : __('Create PO from selected') }}
            </flux:button>
        @endif
        </div>
    </div>

    <div class="mt-6 flex items-center gap-1.5 overflow-x-auto pb-1">
        @foreach (PurchaseRequisitionStatus::cases() as $s)
            <button type="button" wire:click="$set('status', '{{ $s->value }}')"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap cursor-pointer transition-colors duration-150 active:scale-[0.97]
                    {{ $status === $s->value ? 'bg-accent text-accent-foreground' : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-white/5' }}">
                {{ $s->label() }}
                <span class="font-data tabular-nums {{ $status === $s->value ? 'text-accent-foreground/70' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $this->counts[$s->value] ?? 0 }}</span>
            </button>
        @endforeach
    </div>
    <div class="mt-1 border-b border-zinc-200 dark:border-white/10"></div>

    <div class="mt-4">
        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search request or material...')" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,status" class="mt-4 rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden transition-opacity">
        @forelse ($this->requisitions as $req)
            @php($overdue = $req->status === PurchaseRequisitionStatus::Submitted && $req->needed_by->lt(now()->startOfDay()))
            <div wire:key="pr-{{ $req->id }}" class="flex flex-wrap items-start gap-3 px-4 py-3">
                @if ($selectable)
                    <div class="pt-0.5"><flux:checkbox wire:model.live="selected.{{ $req->id }}" :aria-label="__('Select :n', ['n' => $req->requisition_number])" /></div>
                @endif
                <div class="min-w-0 flex-1">
                    <p class="text-sm">
                        <a href="{{ route('purchasing.requisitions.show', $req) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $req->requisition_number }}</a>
                    </p>
                    <p class="mt-0.5 text-xs text-zinc-600 dark:text-zinc-300">{{ $req->items->map(fn ($i) => $this->itemLine($i))->implode(', ') }}</p>
                    <p class="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">
                        {{ __('Requested by :name on :date', ['name' => $req->createdBy->name, 'date' => $req->created_at->translatedFormat('d M Y')]) }}
                        @if ($req->purchaseOrder && $req->status === PurchaseRequisitionStatus::Submitted) · <span class="text-amber-700 dark:text-amber-400">{{ __('PO :n rejected', ['n' => $req->purchaseOrder->po_number]) }}</span>@elseif ($req->purchaseOrder) · {{ __('PO :n', ['n' => $req->purchaseOrder->po_number]) }}@endif
                    </p>
                </div>
                <div class="text-right">
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap {{ $req->status->badgeClasses() }}">{{ $req->status->label() }}</span>
                    <p class="mt-1 text-[11px] whitespace-nowrap {{ $overdue ? 'font-medium text-red-700 dark:text-red-400' : 'text-zinc-500 dark:text-zinc-400' }}">{{ __('Needed by :date', ['date' => $req->needed_by->translatedFormat('d M Y')]) }}</p>
                </div>
            </div>
        @empty
            <p class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No purchase requests here.') }}</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $this->requisitions->links() }}</div>
</section>
