<?php

use App\Actions\Production\CancelWorkOrderAction;
use App\Actions\Production\ReleaseWorkOrderAction;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InactiveEmployeeException;
use App\Exceptions\InvalidWorkOrderStatusTransitionException;
use App\Exceptions\MissingLaborRateException;
use App\Exceptions\MissingOverheadRateException;
use App\Exceptions\WorkOrderRequiresLaborException;
use App\Models\ProductionResult;
use App\Models\WorkOrder;
use App\Support\IncomingStock;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Work Order Detail')] class extends Component {
    public WorkOrder $workOrder;

    public function mount(): void
    {
        Gate::authorize('view', $this->workOrder);

        $this->workOrder->load(['product', 'productionFormula', 'workCenter', 'materials.product', 'createdBy', 'cancelledBy', 'labors.employee', 'productionResult']);
    }

    public string $cancelReason = '';

    public function cancelWorkOrder(): void
    {
        Gate::authorize('cancel', $this->workOrder);

        $this->validate(
            ['cancelReason' => ['required', 'string', 'max:1000']],
            [],
            ['cancelReason' => __('Reason')],
        );

        try {
            app(CancelWorkOrderAction::class)->handle($this->workOrder, Auth::id(), $this->cancelReason);
        } catch (InvalidWorkOrderStatusTransitionException $e) {
            $this->modal('cancel-wo')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->cancelReason = '';
        $this->workOrder->refresh()->load('cancelledBy');
        $this->modal('cancel-wo')->close();
        Flux::toast(variant: 'success', text: __('Work order cancelled.'));
    }

    public function release(): void
    {
        Gate::authorize('release', $this->workOrder);

        try {
            app(ReleaseWorkOrderAction::class)->handle($this->workOrder);
        } catch (WorkOrderRequiresLaborException|InvalidWorkOrderStatusTransitionException|InactiveEmployeeException|MissingLaborRateException|MissingOverheadRateException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->workOrder->refresh()->load(['labors.employee', 'workCenter']);
        unset($this->plannedLaborCost);
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

    #[Computed]
    public function plannedMaterials(): Collection
    {
        $issued = $this->workOrder->issuedQuantities();
        $checkShortage = ! $this->workOrder->status->isTerminal();

        return $this->workOrder->materials->map(function ($material) use ($issued, $checkShortage) {
            $alreadyIssued = $issued[$material->product_id] ?? '0';
            $stillNeeded = bcsub((string) $material->quantity_planned, $alreadyIssued, 2);
            $stillNeeded = bccomp($stillNeeded, '0', 2) > 0 ? $stillNeeded : '0';
            $stock = (string) $material->product->current_stock;
            $shortage = bcsub($stillNeeded, $stock, 2);
            $incoming = collect($this->incoming[$material->product_id] ?? [])
                ->reduce(fn (string $carry, array $row) => bcadd($carry, $row['quantity'], 2), '0');

            return [
                'product' => $material->product,
                'required' => (string) $material->quantity_planned,
                'issued' => $alreadyIssued,
                'stock' => $stock,
                'shortage' => $checkShortage && bccomp($shortage, '0', 2) > 0 ? $shortage : null,
                'incoming' => $incoming,
                'status' => match (true) {
                    ! $checkShortage => null,
                    bccomp($shortage, '0', 2) <= 0 => 'enough',
                    bccomp(bcadd($stock, $incoming, 2), $stillNeeded, 2) >= 0 => 'incoming',
                    default => 'short',
                },
            ];
        });
    }

    /**
     * @return array<int, list<array{source: string, number: string, party: string, quantity: string, date: ?\Carbon\CarbonInterface, note: string, ready: bool}>>
     */
    #[Computed]
    public function incoming(): array
    {
        return IncomingStock::forProducts($this->workOrder->materials->pluck('product_id'), exceptWorkOrderId: $this->workOrder->id);
    }

    #[Computed]
    public function shortMaterials(): Collection
    {
        return $this->workOrder->materials
            ->filter(fn ($material) => bccomp((string) $material->product->current_stock, (string) $material->quantity_planned, 2) < 0)
            ->map(fn ($material) => [
                'product' => $material->product,
                'required' => (string) $material->quantity_planned,
                'stock' => (string) $material->product->current_stock,
            ])
            ->values();
    }

    #[Computed]
    public function outputProgress(): array
    {
        if ($this->workOrder->status === WorkOrderStatus::Completed) {
            return ['percentage' => 100, 'barPercentage' => 100, 'stage' => __('Completed')];
        }

        $materials = $this->workOrder->materials;
        if ($materials->isEmpty() || $this->workOrder->status === WorkOrderStatus::Cancelled) {
            return ['percentage' => 0, 'barPercentage' => 0, 'stage' => $this->workOrder->status->label()];
        }

        $issued = $this->workOrder->issuedQuantities();
        $shares = $materials->map(function ($material) use ($issued) {
            $planned = (string) $material->quantity_planned;
            if (bccomp($planned, '0', 2) <= 0) {
                return '1';
            }
            $share = bcdiv($issued[$material->product_id] ?? '0', $planned, 4);

            return bccomp($share, '1', 4) > 0 ? '1' : $share;
        });

        $average = bcdiv($shares->reduce(fn (string $carry, string $share) => bcadd($carry, $share, 4), '0'), (string) $shares->count(), 4);
        $percentage = (int) floor((float) bcmul($average, '50', 4));
        $fullyIssued = $shares->filter(fn ($share) => bccomp($share, '1', 4) === 0)->count();

        return [
            'percentage' => $percentage,
            'barPercentage' => $percentage,
            'stage' => $percentage === 0
                ? __('Waiting for materials')
                : __('Materials issued :done/:total', ['done' => $fullyIssued, 'total' => $shares->count()]),
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
                <flux:button variant="primary" icon="printer" :href="route('production.work-orders.print', $workOrder)" target="_blank" class="active:scale-[0.95]">
                    {{ __('Print') }}
                </flux:button>
            @endcan

            @if ($workOrder->productionResult)
                <flux:button variant="filled" icon="clipboard-document-check" :href="route('production.results.show', $workOrder->productionResult)" wire:navigate class="active:scale-[0.95]">
                    {{ __('Production Result') }}
                </flux:button>
            @elseif ($workOrder->canRecordResult())
                @can('create', ProductionResult::class)
                    <flux:button variant="primary" icon="clipboard-document-check" :href="route('production.results.create', $workOrder)" wire:navigate class="active:scale-[0.95]">
                        {{ __('Record result') }}
                    </flux:button>
                @endcan
            @endif

            @can('update', $workOrder)
                <flux:button variant="filled" icon="pencil-square" :href="route('production.work-orders.edit', $workOrder)" wire:navigate class="active:scale-[0.95]">
                    {{ __('Edit') }}
                </flux:button>
            @endcan

            @can('cancel', $workOrder)
                <flux:modal.trigger name="cancel-wo">
                    <flux:button variant="ghost" icon="x-mark" class="text-red-600 hover:text-red-700 dark:text-red-400 active:scale-[0.95]">
                        {{ __('Cancel Work Order') }}
                    </flux:button>
                </flux:modal.trigger>
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

    @if ($workOrder->status === WorkOrderStatus::Cancelled)
        <div class="mt-4 flex items-start gap-2.5 rounded-md border border-zinc-200 dark:border-white/10 bg-zinc-50 dark:bg-white/5 px-3.5 py-2.5 motion-safe:animate-fade-slide-up">
            <flux:icon.x-circle variant="mini" class="size-4 mt-0.5 shrink-0 text-zinc-500 dark:text-zinc-400" />
            <div class="text-xs">
                <p class="font-semibold text-zinc-800 dark:text-zinc-200">
                    {{ __('Cancelled by :name', ['name' => $workOrder->cancelledBy?->name ?? '—']) }}
                    @if ($workOrder->cancelled_at) · <span class="font-data tabular-nums">{{ $workOrder->cancelled_at->format('d M Y H:i') }}</span> @endif
                </p>
                @if ($workOrder->cancel_reason)
                    <p class="mt-0.5 text-zinc-600 dark:text-zinc-400">“{{ $workOrder->cancel_reason }}”</p>
                @endif
            </div>
        </div>
    @endif

    @can('cancel', $workOrder)
        <flux:modal name="cancel-wo" :show="$errors->has('cancelReason')" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Cancel this work order?') }}</flux:heading>
                    <flux:subheading>
                        {{ __('It will not be produced. A cancelled work order cannot be reopened — create a new one if it is needed later.') }}
                    </flux:subheading>
                    @if ($workOrder->status === WorkOrderStatus::Released)
                        <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Material issue drafts for it are cancelled too. Nothing has left the warehouse yet.') }}</p>
                    @endif
                </div>

                <flux:field>
                    <flux:label>{{ __('Reason') }}</flux:label>
                    <flux:textarea wire:model="cancelReason" rows="3" :placeholder="__('e.g. customer order cancelled, plan merged into another WO')" />
                    <flux:error name="cancelReason" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" class="active:scale-[0.95]">{{ __('Keep it') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="cancelWorkOrder" wire:loading.attr="disabled" wire:target="cancelWorkOrder" class="active:scale-[0.95]">
                        {{ __('Cancel Work Order') }}
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan

    @can('release', $workOrder)
        <flux:modal name="release-wo" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Release this work order?') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Releasing hands the work order to the production floor. It can no longer go back to Draft, and the warehouse can start issuing materials for it.') }}
                    </flux:subheading>
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('Labor and overhead rates are updated to the rates in effect today.') }}
                    </p>
                </div>

                @if ($this->shortMaterials->isNotEmpty())
                    <div class="rounded-md border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 px-3 py-2.5">
                        <p class="flex items-center gap-1.5 text-xs font-semibold text-amber-800 dark:text-amber-300">
                            <flux:icon.exclamation-triangle variant="micro" class="size-3.5" />
                            {{ __('Not enough stock for :count material(s)', ['count' => $this->shortMaterials->count()]) }}
                        </p>
                        <ul class="mt-1.5 space-y-1">
                            @foreach ($this->shortMaterials as $short)
                                <li class="flex items-center justify-between gap-3 text-xs text-amber-800 dark:text-amber-300">
                                    <span>{{ $short['product']->product_name }}</span>
                                    <span class="font-data tabular-nums">{{ __('need') }} {{ $this->formatQuantity($short['required']) }} · {{ __('stock') }} {{ $this->formatQuantity($short['stock']) }} {{ $short['product']->unit_of_measure }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-[11px] text-amber-700 dark:text-amber-400">{{ __('You can still release it. The warehouse cannot issue more than what is in stock.') }}</p>
                    </div>
                @endif

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

    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4">
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
            </dl>
        </div>

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
                    @php($done = $this->outputProgress['percentage'] === 100)
                    <div class="relative mt-1.5">
                        <div class="h-1.5 rounded-full bg-zinc-100 dark:bg-white/10 overflow-hidden"
                             role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $this->outputProgress['barPercentage'] }}" aria-valuetext="{{ $this->outputProgress['percentage'] }}% — {{ $this->outputProgress['stage'] }}">
                            <div class="h-full w-full origin-left rounded-full {{ $done ? 'bg-green-500' : 'bg-accent' }} transition-transform duration-500 motion-reduce:transition-none" style="transform: scaleX({{ $this->outputProgress['barPercentage'] / 100 }});"></div>
                        </div>
                        <span class="absolute left-1/2 -top-0.5 h-2.5 w-px bg-zinc-300 dark:bg-white/25" aria-hidden="true"></span>
                    </div>
                    <p class="mt-1.5 text-[11px] {{ $done ? 'text-green-600 dark:text-green-400' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $this->outputProgress['stage'] }}</p>
                </div>
            </div>

            <div class="mt-auto px-5 py-4 bg-accent">
                <p class="text-[10px] font-semibold uppercase tracking-widest text-accent-foreground/70">{{ __('Target Output') }}</p>
                <p class="font-data text-xl font-medium tabular-nums text-accent-foreground mt-0.5">{{ $this->formatQuantity((string) $workOrder->quantity_target) }} {{ $workOrder->product->unit_of_measure }}</p>
            </div>
        </div>
    </div>

    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 120ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Workers') }}</h2>
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">{{ __('Hourly rates are copied from Accounting\'s labor rates at the time the work order was created.') }}</p>

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

    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 130ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Planned Materials') }}</h2>
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">{{ __('Copied from the formula when this work order was created. Actual consumption is recorded when materials are issued.') }}</p>

        <div class="hidden sm:block overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Material') }}</th>
                        <th class="py-2 px-3">{{ __('Type') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Planned') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Already issued') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('In Stock') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Incoming') }}</th>
                        <th class="py-2 px-3">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->plannedMaterials as $row)
                        @php($unitOfMeasure = $row['product']->unit_of_measure)
                        <tr wire:key="mat-{{ $row['product']->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                            <td class="py-2 px-3">
                                <p class="font-data text-accent">{{ $row['product']->product_code }}</p>
                                <p class="text-zinc-700 dark:text-zinc-300">{{ $row['product']->product_name }}</p>
                            </td>
                            <td class="py-2 px-3 text-zinc-500 dark:text-zinc-400">{{ __($row['product']->type->label()) }}</td>
                            <td class="py-2 px-3 text-right font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatQuantity($row['required']) }} {{ $unitOfMeasure }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap {{ bccomp($row['issued'], $row['required'], 2) >= 0 ? 'text-green-600 dark:text-green-400' : 'text-zinc-600 dark:text-zinc-400' }}">{{ $this->formatQuantity($row['issued']) }} {{ $unitOfMeasure }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap {{ $row['shortage'] ? 'text-red-600 dark:text-red-400 font-medium' : 'text-zinc-600 dark:text-zinc-400' }}">
                                {{ $this->formatQuantity($row['stock']) }} {{ $unitOfMeasure }}
                            </td>
                            <td class="py-2 px-3 text-right whitespace-nowrap">
                                @if (bccomp($row['incoming'], '0', 2) > 0)
                                    <flux:modal.trigger name="incoming-{{ $row['product']->id }}">
                                        <button type="button" class="group/in -my-1 inline-flex items-center gap-0.5 rounded px-1 py-1 font-data tabular-nums text-zinc-700 dark:text-zinc-300 underline decoration-dotted decoration-zinc-400 underline-offset-4 hover:text-accent hover:decoration-accent focus-visible:outline-2 focus-visible:outline-accent cursor-pointer transition-colors duration-150"
                                            aria-label="{{ __('Incoming') }} {{ $row['product']->product_name }}: {{ $this->formatQuantity($row['incoming']) }} {{ $unitOfMeasure }}">
                                            {{ $this->formatQuantity($row['incoming']) }} {{ $unitOfMeasure }}
                                            <flux:icon.chevron-right variant="micro" class="size-3.5 text-zinc-400 group-hover/in:text-accent transition-transform duration-150 group-hover/in:translate-x-0.5" />
                                        </button>
                                    </flux:modal.trigger>
                                @else
                                    <span class="font-data text-zinc-400 dark:text-zinc-500">—</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 whitespace-nowrap">
                                <x-material-status :status="$row['status']" :shortage="$row['shortage']" :unit="$unitOfMeasure" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="sm:hidden rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
            @foreach ($this->plannedMaterials as $row)
                @php($unitOfMeasure = $row['product']->unit_of_measure)
                <div wire:key="mat-m-{{ $row['product']->id }}" class="px-4 py-3 text-xs">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-data text-accent">{{ $row['product']->product_code }}</p>
                            <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $row['product']->product_name }}</p>
                            <p class="text-zinc-500 dark:text-zinc-400 mt-0.5">{{ __($row['product']->type->label()) }}</p>
                        </div>
                        <div class="text-right">
                            <p class="font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatQuantity($row['required']) }} {{ $unitOfMeasure }}</p>
                            <p class="font-data text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ __('Already issued') }} {{ $this->formatQuantity($row['issued']) }}</p>
                        </div>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                        <x-material-status :status="$row['status']" :shortage="$row['shortage']" :unit="$unitOfMeasure" />
                        <div class="flex items-center gap-3 font-data tabular-nums">
                            <span class="{{ $row['shortage'] ? 'text-red-600 dark:text-red-400' : 'text-zinc-600 dark:text-zinc-400' }}">{{ __('In Stock') }} {{ $this->formatQuantity($row['stock']) }}</span>
                            @if (bccomp($row['incoming'], '0', 2) > 0)
                                <flux:modal.trigger name="incoming-{{ $row['product']->id }}">
                                    <button type="button" class="-my-2 inline-flex items-center gap-0.5 py-2 text-zinc-700 dark:text-zinc-300 underline decoration-dotted underline-offset-4 cursor-pointer">
                                        {{ __('Incoming') }} {{ $this->formatQuantity($row['incoming']) }}
                                        <flux:icon.chevron-right variant="micro" class="size-3.5 text-zinc-400" />
                                    </button>
                                </flux:modal.trigger>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    @foreach ($this->plannedMaterials as $row)
        @continue(bccomp($row['incoming'], '0', 2) <= 0)
        @php($unitOfMeasure = $row['product']->unit_of_measure)
        <flux:modal name="incoming-{{ $row['product']->id }}" class="max-w-xl">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Incoming') }} · {{ $row['product']->product_name }}</flux:heading>
                    <flux:subheading>{{ __('Expected stock from open purchase orders and work orders still in production.') }}</flux:subheading>
                </div>

                <div class="rounded-md border border-zinc-200 dark:border-white/10 divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach ($this->incoming[$row['product']->id] ?? [] as $source)
                        @php($late = ! $source['ready'] && $source['date'] && $source['date']->isPast() && ! $source['date']->isToday())
                        <div class="flex items-start justify-between gap-3 px-3 py-2.5 text-xs">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-1.5">
                                    <span class="inline-flex items-center rounded-full border px-1.5 py-px text-[10px] font-medium {{ $source['source'] === 'purchase' ? 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-500/20 dark:bg-blue-500/10 dark:text-blue-400' : 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-400' }}">
                                        {{ $source['source'] === 'purchase' ? __('Purchase') : __('Production') }}
                                    </span>
                                    <span class="font-data text-zinc-800 dark:text-zinc-200">{{ $source['number'] }}</span>
                                </p>
                                <p class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ $source['party'] }} · {{ $source['note'] }}</p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="font-data font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatQuantity($source['quantity']) }} {{ $unitOfMeasure }}</p>
                                <p class="font-data tabular-nums {{ $late ? 'text-red-600 dark:text-red-400' : 'text-zinc-500 dark:text-zinc-400' }}">
                                    @if ($source['date'])
                                        {{ $source['ready'] ? __('finished') : __('expected') }} {{ $source['date']->format('d M Y') }}@if ($late) · {{ __('late') }}@endif
                                    @else
                                        {{ __('no date') }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-end">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Close') }}</flux:button></flux:modal.close>
                </div>
            </div>
        </flux:modal>
    @endforeach
</section>
