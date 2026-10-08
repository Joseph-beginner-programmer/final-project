<?php

use App\Actions\Production\ReleaseWorkOrderAction;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidWorkOrderStatusTransitionException;
use App\Exceptions\WorkOrderRequiresLaborException;
use App\Models\WorkOrder;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Work Order Detail')] class extends Component {
    public WorkOrder $workOrder;

    public function mount(): void
    {
        Gate::authorize('view', $this->workOrder);

        $this->workOrder->load(['product', 'productionFormula', 'workCenter', 'materials.product', 'createdBy', 'closedBy', 'labors.employee']);
    }

    public function release(): void
    {
        Gate::authorize('release', $this->workOrder);

        try {
            app(ReleaseWorkOrderAction::class)->handle($this->workOrder);
        } catch (WorkOrderRequiresLaborException|InvalidWorkOrderStatusTransitionException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->workOrder->refresh();
        $this->modal('release-wo')->close();

        Flux::toast(variant: 'success', text: __('Work order released to the production floor.'));
    }

    #[Computed]
    public function plannedLaborCost(): string
    {
        return $this->workOrder->labors->reduce(fn (string $carry, $labor) => bcadd($carry, (string) $labor->planned_cost, 2), '0');
    }

    #[Computed]
    public function plannedLaborHours(): string
    {
        return $this->workOrder->labors->reduce(fn (string $carry, $labor) => bcadd($carry, (string) $labor->planned_hours, 2), '0');
    }

    public function formatRupiah(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 0, ',', '.');
    }

    /**
     * The WO's own copied plan (work_order_materials) — deliberately not recomputed from the
     * formula, so a later formula edit can't change what this WO was approved with.
     */
    #[Computed]
    public function plannedMaterials(): Collection
    {
        return $this->workOrder->materials->map(fn ($material) => [
            'product' => $material->product,
            'required' => (string) $material->quantity_planned,
        ]);
    }

    #[Computed]
    public function outputProgress(): array
    {
        $target = (string) $this->workOrder->quantity_target;
        $good = (string) $this->workOrder->quantity_good;

        $percentage = bccomp($target, '0', 2) > 0
            ? (int) round((float) bcdiv(bcmul($good, '100', 4), $target, 4))
            : 0;

        return [
            'percentage' => $percentage,
            'barPercentage' => min(100, $percentage), // clamped so the bar never overflows its track
        ];
    }

    public function formatQuantity(string $quantity): string
    {
        if (! str_contains($quantity, '.')) {
            return $quantity;
        }

        return rtrim(rtrim($quantity, '0'), '.');
    }

    public function statusBadgeClasses(WorkOrderStatus $status): string
    {
        return match ($status) {
            WorkOrderStatus::Draft => 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:border-white/10',
            WorkOrderStatus::Released => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-400 dark:border-blue-500/20',
            WorkOrderStatus::InProgress => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20',
            WorkOrderStatus::Completed => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
            WorkOrderStatus::CompletedShort => 'bg-teal-50 text-teal-700 border-teal-200 dark:bg-teal-500/10 dark:text-teal-400 dark:border-teal-500/20',
            WorkOrderStatus::Cancelled => 'bg-zinc-100 text-zinc-500 border-zinc-200 line-through dark:bg-white/5 dark:text-zinc-500 dark:border-white/10',
        };
    }
}; ?>

<style>
    @keyframes fade-slide-up {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .motion-safe\:animate-fade-slide-up {
        animation: fade-slide-up .5s cubic-bezier(.16,1,.3,1) both;
    }
    @media (prefers-reduced-motion: reduce) {
        .motion-safe\:animate-fade-slide-up { animation: none; }
    }
</style>

@php($isLate = $workOrder->planned_end_date->isPast() && !$workOrder->status->isTerminal())

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('production.dashboard')" wire:navigate>{{ __('Production') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('production.work-orders.list')" wire:navigate>{{ __('Work Orders') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $workOrder->wo_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-data text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                    {{ $workOrder->wo_number }}
                </h1>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium whitespace-nowrap {{ $this->statusBadgeClasses($workOrder->status) }}">
                    {{ $workOrder->status->label() }}
                </span>
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                {{ __('Created by') }} {{ $workOrder->createdBy->name }} &middot; {{ $workOrder->created_at->format('d M Y') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" :href="route('production.work-orders.list')" wire:navigate class="active:scale-[0.95]">
                {{ __('Back to List') }}
            </flux:button>

            @can('print', $workOrder)
                {{-- new tab, no wire:navigate: it's a standalone print document --}}
                <flux:button variant="primary" icon="printer" :href="route('production.work-orders.print', $workOrder)" target="_blank" class="active:scale-[0.95]">
                    {{ __('Print') }}
                </flux:button>
            @endcan

            @can('release', $workOrder)
                <flux:modal.trigger name="release-wo">
                    <flux:button variant="primary" icon="play" class="active:scale-[0.95]">
                        {{ __('Release') }}
                    </flux:button>
                </flux:modal.trigger>
            @endcan
        </div>
    </div>

    @can('release', $workOrder)
        <flux:modal name="release-wo" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Release this work order?') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Releasing hands the work order to the production floor. It can no longer go back to Draft, and the warehouse can start issuing materials for it.') }}
                    </flux:subheading>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button variant="primary" wire:click="release" wire:loading.attr="disabled" wire:target="release" class="active:scale-[0.95]">
                        {{ __('Release Work Order') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan

    {{-- Info cards --}}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Product & formula --}}
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-5 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
            <h2 class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Product') }}</h2>
            <p class="mt-3 font-data text-xs text-accent">{{ $workOrder->product->product_code }}</p>
            <p class="font-display text-base font-bold text-zinc-900 dark:text-white">{{ $workOrder->product->product_name }}</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $workOrder->product->type->label() }}</p>

            <dl class="mt-4 pt-3 border-t border-zinc-100 dark:border-white/10 space-y-2 text-xs">
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Formula') }}</dt>
                    <dd class="font-data text-zinc-800 dark:text-zinc-200">{{ $workOrder->productionFormula->formula_code }} v{{ $workOrder->productionFormula->version }}</dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Work Center') }}</dt>
                    <dd class="text-zinc-800 dark:text-zinc-200">{{ __($workOrder->workCenter->name) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Recipe basis') }}</dt>
                    <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatQuantity((string) $workOrder->productionFormula->output_quantity) }} {{ $workOrder->product->unit_of_measure }}</dd>
                </div>
            </dl>
        </div>

        {{-- Schedule --}}
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-5 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
            <h2 class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Schedule') }}</h2>
            <dl class="mt-3 space-y-2.5 text-sm">
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Planned Start') }}</dt>
                    <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $workOrder->planned_start_date->format('d M Y') }}</dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Planned End') }}</dt>
                    <dd>
                        @if ($isLate)
                            <span class="inline-flex items-center gap-1 rounded-full border border-red-200 dark:border-red-500/20 bg-red-50 dark:bg-red-500/10 px-2 py-0.5 font-data tabular-nums text-xs text-red-700 dark:text-red-400">
                                <flux:icon.clock variant="micro" class="size-3" />
                                {{ $workOrder->planned_end_date->format('d M Y') }}
                            </span>
                        @else
                            <span class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $workOrder->planned_end_date->format('d M Y') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Duration') }}</dt>
                    <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $workOrder->planned_start_date->diffInDays($workOrder->planned_end_date) + 1 }} {{ __('days') }}</dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Last printed') }}</dt>
                    <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $workOrder->printed_at?->format('d M Y H:i') ?? '—' }}</dd>
                </div>
                @if ($workOrder->closed_at)
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Closed By') }}</dt>
                        <dd class="text-zinc-800 dark:text-zinc-200">{{ $workOrder->closedBy?->name ?? '—' }} &middot; <span class="font-data tabular-nums">{{ $workOrder->closed_at->format('d M Y') }}</span></dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Output --}}
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 flex flex-col h-full overflow-hidden motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
            <div class="p-5">
                <h2 class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Output') }}</h2>
                <dl class="mt-3 space-y-2.5 text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Good') }}</dt>
                        <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatQuantity((string) $workOrder->quantity_good) }} {{ $workOrder->product->unit_of_measure }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Reject') }}</dt>
                        <dd class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatQuantity((string) $workOrder->quantity_reject) }} {{ $workOrder->product->unit_of_measure }}</dd>
                    </div>
                </dl>

                <div class="mt-4">
                    <div class="flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                        <span>{{ __('Progress') }}</span>
                        <span class="font-data tabular-nums">{{ $this->outputProgress['percentage'] }}%</span>
                    </div>
                    <div class="mt-1.5 h-1.5 rounded-full bg-zinc-100 dark:bg-white/10 overflow-hidden"
                         role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $this->outputProgress['barPercentage'] }}">
                        <div class="h-full w-full origin-left rounded-full bg-accent transition-transform duration-500" style="transform: scaleX({{ $this->outputProgress['barPercentage'] / 100 }});"></div>
                    </div>
                </div>
            </div>

            <div class="mt-auto px-5 py-4 bg-accent">
                <p class="text-[10px] font-semibold uppercase tracking-widest text-accent-foreground/70">{{ __('Target Output') }}</p>
                <p class="font-data text-xl font-medium tabular-nums text-accent-foreground mt-0.5">{{ $this->formatQuantity((string) $workOrder->quantity_target) }} {{ $workOrder->product->unit_of_measure }}</p>
            </div>
        </div>
    </div>

    {{-- Workers --}}
    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 120ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Workers') }}</h2>
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">{{ __('Hourly rates are copied from Accounting\'s labor rates at the time the work order was created.') }}</p>

        {{-- Desktop --}}
        <div class="hidden sm:block overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Worker') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Planned Hours') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Rate / Hour') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Cost') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($workOrder->labors as $labor)
                        <tr wire:key="labor-{{ $labor->id }}" class="border-b border-zinc-100 dark:border-white/5 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                            <td class="py-2 px-3">
                                <p class="font-data text-accent">{{ $labor->employee->employee_code }}</p>
                                <p class="text-zinc-700 dark:text-zinc-300">{{ $labor->employee->name }}</p>
                            </td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300 whitespace-nowrap">{{ $this->formatQuantity((string) $labor->planned_hours) }} {{ __('hrs') }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ $this->formatRupiah((string) $labor->hourly_rate) }}</td>
                            <td class="py-2 px-3 text-right font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatRupiah((string) $labor->planned_cost) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-8 text-center text-zinc-500 dark:text-zinc-400">{{ __('No workers assigned.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($workOrder->labors->isNotEmpty())
                    <tfoot>
                        <tr class="bg-zinc-50 dark:bg-white/5 border-t border-zinc-200 dark:border-white/10">
                            <td class="py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Planned Labor Cost') }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity($this->plannedLaborHours) }} {{ __('hrs') }}</td>
                            <td></td>
                            <td class="py-2 px-3 text-right font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatRupiah($this->plannedLaborCost) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        {{-- Mobile --}}
        <div class="sm:hidden rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
            @forelse ($workOrder->labors as $labor)
                <div wire:key="labor-m-{{ $labor->id }}" class="px-4 py-3 flex items-start justify-between gap-3 text-xs">
                    <div>
                        <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $labor->employee->name }}</p>
                        <p class="text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $this->formatQuantity((string) $labor->planned_hours) }} {{ __('hrs') }} &times; {{ $this->formatRupiah((string) $labor->hourly_rate) }}</p>
                    </div>
                    <p class="font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatRupiah((string) $labor->planned_cost) }}</p>
                </div>
            @empty
                <p class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No workers assigned.') }}</p>
            @endforelse
            @if ($workOrder->labors->isNotEmpty())
                <div class="px-4 py-3 flex items-center justify-between gap-3 text-xs bg-zinc-50 dark:bg-white/5">
                    <span class="font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Planned Labor Cost') }}</span>
                    <span class="font-data font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($this->plannedLaborCost) }}</span>
                </div>
            @endif
        </div>
    </div>

    {{-- Overhead --}}
    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 125ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Machine & Overhead') }}</h2>
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">{{ __('Applied overhead (electricity, depreciation) at the work center\'s rate when the work order was created.') }}</p>

        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 grid grid-cols-2 sm:grid-cols-4 divide-y sm:divide-y-0 sm:divide-x divide-zinc-100 dark:divide-white/5">
            <div class="px-4 py-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Work Center') }}</p>
                <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-200">{{ __($workOrder->workCenter->name) }}</p>
            </div>
            <div class="px-4 py-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Machine Hours') }}</p>
                <p class="mt-1 font-data text-sm tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->formatQuantity((string) $workOrder->planned_machine_hours) }} {{ __('hrs') }}</p>
            </div>
            <div class="px-4 py-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Rate / Machine Hour') }}</p>
                <p class="mt-1 font-data text-sm tabular-nums text-zinc-500 dark:text-zinc-400">{{ $this->formatRupiah((string) $workOrder->overhead_rate) }}</p>
            </div>
            <div class="px-4 py-3">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Planned Overhead') }}</p>
                <p class="mt-1 font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $workOrder->planned_overhead_cost) }}</p>
            </div>
        </div>
    </div>

    {{-- Planned materials --}}
    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 130ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Planned Materials') }}</h2>
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">{{ __('Copied from the formula when this work order was created. Actual consumption is recorded when materials are issued.') }}</p>

        {{-- Desktop --}}
        <div class="hidden sm:block overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Material') }}</th>
                        <th class="py-2 px-3">{{ __('Type') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Planned') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->plannedMaterials as $row)
                        <tr wire:key="mat-{{ $row['product']->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                            <td class="py-2 px-3">
                                <p class="font-data text-accent">{{ $row['product']->product_code }}</p>
                                <p class="text-zinc-700 dark:text-zinc-300">{{ $row['product']->product_name }}</p>
                            </td>
                            <td class="py-2 px-3 text-zinc-500 dark:text-zinc-400">{{ __($row['product']->type->label()) }}</td>
                            <td class="py-2 px-3 text-right font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatQuantity($row['required']) }} {{ $row['product']->unit_of_measure }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile --}}
        <div class="sm:hidden rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
            @foreach ($this->plannedMaterials as $row)
                <div wire:key="mat-m-{{ $row['product']->id }}" class="px-4 py-3 flex items-start justify-between gap-3 text-xs">
                    <div>
                        <p class="font-data text-accent">{{ $row['product']->product_code }}</p>
                        <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $row['product']->product_name }}</p>
                        <p class="text-zinc-500 dark:text-zinc-400 mt-0.5">{{ __($row['product']->type->label()) }}</p>
                    </div>
                    <p class="font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatQuantity($row['required']) }} {{ $row['product']->unit_of_measure }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
