<?php

use App\Enums\DocumentStatus;
use App\Enums\WorkOrderStatus;
use App\Models\MaterialIssue;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Material Issue')] class extends Component {
    use WithPagination;

    #[Url]
    public string $tab = 'work-orders';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', MaterialIssue::class);
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
     * Work Orders on the floor that can receive materials, with how much of their plan is already issued.
     */
    #[Computed]
    public function workOrders(): Collection
    {
        $term = $this->term();

        return WorkOrder::query()
            ->whereIn('status', [WorkOrderStatus::Released->value, WorkOrderStatus::InProgress->value])
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('wo_number', 'like', "%{$term}%")
                ->orWhereHas('product', fn (Builder $q) => $q->where('product_name', 'like', "%{$term}%"))))
            ->with(['product', 'materials', 'materialIssues' => fn ($q) => $q->where('status', DocumentStatus::Draft->value)])
            ->orderBy('planned_start_date')
            ->get()
            ->map(function (WorkOrder $workOrder) {
                $issued = $workOrder->issuedQuantities();
                $workOrder->setAttribute('materials_total', $workOrder->materials->count());
                $workOrder->setAttribute('materials_fulfilled', $workOrder->materials->filter(
                    fn ($m) => bccomp($issued[$m->product_id] ?? '0', (string) $m->quantity_planned, 2) >= 0
                )->count());
                $workOrder->setAttribute('open_draft', $workOrder->materialIssues->first());

                return $workOrder;
            });
    }

    #[Computed]
    public function issues(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $term = $this->term();

        return MaterialIssue::query()
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('issue_number', 'like', "%{$term}%")
                ->orWhereHas('workOrder', fn (Builder $q) => $q->where('wo_number', 'like', "%{$term}%"))))
            ->with(['workOrder.product', 'items', 'createdBy'])
            ->orderByDesc('id')
            ->paginate(15);
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

    public function workOrderBadgeClasses(WorkOrderStatus $status): string
    {
        return $status === WorkOrderStatus::InProgress
            ? 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20'
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
        <flux:breadcrumbs.item :href="route('warehouse.dashboard')" wire:navigate>{{ __('Warehouse') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Material Issue') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 motion-safe:animate-fade-slide-up">
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
            {{ __('Material Issue') }}
        </h1>
        <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
    </div>

    {{-- Tabs --}}
    <div class="mt-6 flex items-center gap-1.5 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        @foreach (['work-orders' => __('Work orders needing materials'), 'documents' => __('Issue documents')] as $key => $label)
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

    <div class="mt-4 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search"
            :placeholder="$tab === 'work-orders' ? __('Search WO number or product...') : __('Search issue or WO number...')" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,tab" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
        @if ($tab === 'work-orders')
            {{-- Work Orders that can receive materials --}}
            <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
                @forelse ($this->workOrders as $workOrder)
                    @php($complete = $workOrder->materials_total > 0 && $workOrder->materials_fulfilled === $workOrder->materials_total)
                    <div wire:key="wo-{{ $workOrder->id }}" class="flex flex-col sm:flex-row sm:items-center gap-3 px-4 py-3.5 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-data text-sm font-medium text-accent">{{ $workOrder->wo_number }}</span>
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->workOrderBadgeClasses($workOrder->status) }}">{{ __($workOrder->status->label()) }}</span>
                            </div>
                            <p class="mt-0.5 text-sm text-zinc-800 dark:text-zinc-200">{{ $workOrder->product->product_name }}</p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ __('Planned Start') }} <span class="font-data tabular-nums">{{ $workOrder->planned_start_date->format('d M Y') }}</span>
                            </p>
                        </div>

                        {{-- fulfilment: how many planned materials are fully issued --}}
                        <div class="sm:w-44">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Materials issued') }}</span>
                                <span class="font-data tabular-nums {{ $complete ? 'text-green-600 dark:text-green-400' : 'text-zinc-700 dark:text-zinc-300' }}">{{ $workOrder->materials_fulfilled }}/{{ $workOrder->materials_total }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 rounded-full bg-zinc-100 dark:bg-white/10 overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $workOrder->materials_total }}" aria-valuenow="{{ $workOrder->materials_fulfilled }}">
                                <div class="h-full w-full origin-left rounded-full {{ $complete ? 'bg-green-500' : 'bg-accent' }}" style="transform: scaleX({{ $workOrder->materials_total ? $workOrder->materials_fulfilled / $workOrder->materials_total : 0 }});"></div>
                            </div>
                        </div>

                        <div class="sm:w-44 flex sm:justify-end">
                            @if ($workOrder->open_draft)
                                <flux:button size="sm" variant="filled" icon="pencil-square" :href="route('warehouse.outbound.material-issues.show', $workOrder->open_draft)" wire:navigate class="active:scale-[0.95]">
                                    {{ __('Open draft') }}
                                </flux:button>
                            @else
                                <flux:button size="sm" variant="primary" icon="arrow-up-tray" :href="route('warehouse.outbound.material-issues.create', $workOrder)" wire:navigate class="active:scale-[0.95]">
                                    {{ __('Issue materials') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No released work orders need materials right now.') }}</p>
                @endforelse
            </div>
        @else
            {{-- Issue documents --}}
            <div class="hidden md:block overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
                <table class="w-full text-xs border-collapse">
                    <thead>
                        <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                            <th class="py-2 px-3">{{ __('Issue Number') }}</th>
                            <th class="py-2 px-3">{{ __('Work Order') }}</th>
                            <th class="py-2 px-3">{{ __('Created') }}</th>
                            <th class="py-2 px-3">{{ __('Posted') }}</th>
                            <th class="py-2 px-3 text-right">{{ __('Cost') }}</th>
                            <th class="py-2 px-3">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->issues as $issue)
                            <tr wire:key="mi-{{ $issue->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                                <td class="py-2 px-3 whitespace-nowrap">
                                    <a href="{{ route('warehouse.outbound.material-issues.show', $issue) }}" wire:navigate class="font-data text-accent hover:underline">{{ $issue->issue_number }}</a>
                                </td>
                                <td class="py-2 px-3">
                                    <p class="font-data text-zinc-700 dark:text-zinc-300">{{ $issue->workOrder->wo_number }}</p>
                                    <p class="text-zinc-500 dark:text-zinc-400">{{ $issue->workOrder->product->product_name }}</p>
                                </td>
                                <td class="py-2 px-3 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $issue->created_at->format('d M Y') }}</td>
                                <td class="py-2 px-3 font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $issue->issued_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td class="py-2 px-3 text-right font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">
                                    {{ $issue->status === DocumentStatus::Posted ? $this->formatRupiah($issue->totalCost()) : '—' }}
                                </td>
                                <td class="py-2 px-3">
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->documentBadgeClasses($issue->status) }}">{{ __($issue->status->label()) }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No material issues yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="md:hidden rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
                @forelse ($this->issues as $issue)
                    <a wire:key="mi-m-{{ $issue->id }}" href="{{ route('warehouse.outbound.material-issues.show', $issue) }}" wire:navigate class="block px-4 py-3 hover:bg-zinc-50 dark:hover:bg-white/3 active:scale-[0.98] transition-[background-color,transform]">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-data font-medium text-accent">{{ $issue->issue_number }}</span>
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->documentBadgeClasses($issue->status) }}">{{ __($issue->status->label()) }}</span>
                        </div>
                        <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300"><span class="font-data">{{ $issue->workOrder->wo_number }}</span> · {{ $issue->workOrder->product->product_name }}</p>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 font-data tabular-nums">{{ $issue->created_at->format('d M Y') }}{{ $issue->status === DocumentStatus::Posted ? ' · '.$this->formatRupiah($issue->totalCost()) : '' }}</p>
                    </a>
                @empty
                    <p class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No material issues yet.') }}</p>
                @endforelse
            </div>

            <div class="mt-4">{{ $this->issues->links() }}</div>
        @endif
    </div>
</section>
