<?php

use App\Actions\Production\PostProductionResultAction;
use App\Enums\DocumentStatus;
use App\Exceptions\InactiveEmployeeException;
use App\Exceptions\InvalidDocumentStatusTransitionException;
use App\Exceptions\InvalidProductionResultException;
use App\Exceptions\MissingLaborRateException;
use App\Exceptions\ProductionResultNotEditableException;
use App\Exceptions\WorkOrderNotRecordableException;
use App\Models\Product;
use App\Models\ProductionResult;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Production Result')] class extends Component {
    public ProductionResult $productionResult;

    public function mount(): void
    {
        Gate::authorize('view', $this->productionResult);
        $this->loadRelations();
    }

    protected function loadRelations(): void
    {
        $this->productionResult->load([
            'workOrder.product', 'workOrder.workCenter', 'workOrder.materials', 'workOrder.labors.employee',
            'materials', 'labors.employee', 'createdBy', 'postedBy',
        ]);
    }

    public function post(): void
    {
        Gate::authorize('post', $this->productionResult);

        try {
            app(PostProductionResultAction::class)->handle($this->productionResult, Auth::id());
        } catch (InvalidProductionResultException|WorkOrderNotRecordableException|ProductionResultNotEditableException|InvalidDocumentStatusTransitionException|InactiveEmployeeException|MissingLaborRateException $e) {
            $this->modal('post-result')->close();
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        $this->productionResult->refresh();
        $this->loadRelations();
        unset($this->workerRows, $this->materialRows);
        $this->modal('post-result')->close();
        Flux::toast(variant: 'success', text: __('Posted — the work order is completed and its cost is fixed.'));
    }

    /**
     * Planned workers next to actual hours, plus any worker added on the day.
     */
    #[Computed]
    public function workerRows(): Collection
    {
        $result = $this->productionResult;
        $actual = $result->labors->keyBy('employee_id');

        $rows = $result->workOrder->labors->map(fn ($labor) => [
            'employee' => $labor->employee,
            'planned' => (string) $labor->planned_hours,
            'actual' => isset($actual[$labor->employee_id]) ? (string) $actual[$labor->employee_id]->hours : '0',
            'rate' => $actual[$labor->employee_id]->hourly_rate ?? null,
            'cost' => $actual[$labor->employee_id]->total_cost ?? null,
        ]);

        $plannedIds = $result->workOrder->labors->pluck('employee_id')->all();
        foreach ($result->labors->reject(fn ($labor) => in_array($labor->employee_id, $plannedIds, true)) as $labor) {
            $rows->push([
                'employee' => $labor->employee,
                'planned' => null,
                'actual' => (string) $labor->hours,
                'rate' => $labor->hourly_rate,
                'cost' => $labor->total_cost,
            ]);
        }

        return $rows;
    }

    /**
     * Planned → issued → used → leftover per material, with the used cost once posted.
     */
    #[Computed]
    public function materialRows(): Collection
    {
        $result = $this->productionResult;
        $issued = $result->workOrder->issuedQuantities();
        $planned = $result->workOrder->materials->keyBy('product_id');
        $used = $result->materials->groupBy('product_id');
        $productIds = $planned->keys()->merge(array_keys($issued))->unique();
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');

        return $productIds->map(function ($productId) use ($issued, $planned, $used, $products) {
            $rows = $used->get($productId, collect());
            $usedQty = $rows->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->quantity, 2), '0');
            $issuedQty = $issued[$productId] ?? '0';

            return [
                'product' => $products[$productId],
                'planned' => $planned->has($productId) ? (string) $planned[$productId]->quantity_planned : null,
                'issued' => $issuedQty,
                'used' => $usedQty,
                'leftover' => bcsub($issuedQty, $usedQty, 2),
                'cost' => $rows->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->total_cost, 2), '0'),
            ];
        })->values();
    }

    #[Computed]
    public function plannedLaborCost(): string
    {
        return $this->productionResult->workOrder->labors->reduce(fn (string $carry, $labor) => bcadd($carry, (string) $labor->planned_cost, 2), '0');
    }

    public function difference(?string $planned, string $actual): ?string
    {
        return $planned === null ? null : bcsub($actual, $planned, 2);
    }

    public function formatQuantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    public function formatSigned(?string $difference): string
    {
        if ($difference === null) {
            return '—';
        }

        $sign = bccomp($difference, '0', 2) > 0 ? '+' : '';

        return bccomp($difference, '0', 2) === 0 ? '0' : $sign.$this->formatQuantity($difference);
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

@php($result = $productionResult)
@php($wo = $result->workOrder)
@php($unit = $wo->product->unit_of_measure)
@php($posted = $result->status === DocumentStatus::Posted)
@php($label = 'text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')
<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('production.dashboard')" wire:navigate>{{ __('Production') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('production.results.list', ['tab' => 'results'])" wire:navigate>{{ __('Production Results') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $result->result_number }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="font-data text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ $result->result_number }}</h1>
                <span wire:key="status-{{ $result->status->value }}" wire:transition class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $this->documentBadgeClasses($result->status) }}">{{ $result->status->label() }}</span>
            </div>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">
                {{ __('For') }} <a href="{{ route('production.work-orders.show', $wo) }}" wire:navigate class="font-data text-accent hover:underline">{{ $wo->wo_number }}</a> · {{ $wo->product->product_name }} · {{ __($wo->workCenter->name) }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" :href="route('production.results.list', ['tab' => 'results'])" wire:navigate class="active:scale-[0.95]">{{ __('Back to List') }}</flux:button>
            @can('update', $result)
                <flux:button variant="filled" icon="pencil-square" :href="route('production.results.edit', $result)" wire:navigate class="active:scale-[0.95]">{{ __('Edit') }}</flux:button>
            @endcan
            @can('post', $result)
                <flux:modal.trigger name="post-result">
                    <flux:button variant="primary" icon="check-badge" class="active:scale-[0.95]">{{ __('Post') }}</flux:button>
                </flux:modal.trigger>
            @endcan
        </div>
    </div>

    {{-- Info --}}
    <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y sm:divide-y-0 sm:divide-x divide-zinc-100 dark:divide-white/5 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="px-4 py-3">
            <p class="{{ $label }}">{{ __('Completion date') }}</p>
            <p class="mt-1 font-data text-sm text-zinc-800 dark:text-zinc-200">{{ $result->production_date->format('d M Y') }}</p>
        </div>
        <div class="px-4 py-3">
            <p class="{{ $label }}">{{ __('Recorded by') }}</p>
            <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-200">{{ $result->createdBy->name }}</p>
            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $result->created_at->format('d M Y H:i') }}</p>
        </div>
        <div class="px-4 py-3">
            <p class="{{ $label }}">{{ __('Posted by') }}</p>
            <p class="mt-1 text-sm text-zinc-800 dark:text-zinc-200">{{ $result->postedBy?->name ?? '—' }}</p>
            <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $result->posted_at?->format('d M Y H:i') }}</p>
        </div>
        <div class="px-4 py-3">
            <p class="{{ $label }}">{{ __('Unit cost') }}</p>
            @if ($posted)
                <p class="mt-1 font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $result->unit_cost) }}<span class="text-zinc-500 dark:text-zinc-400 font-normal">/{{ $unit }}</span></p>
            @else
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">—</p>
                <p class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Calculated on posting') }}</p>
            @endif
        </div>
    </div>

    {{-- Output & machine: plan vs actual --}}
    <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 60ms;">
        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Output') }}</h2>
            <div class="mt-3 grid grid-cols-3 gap-3">
                <div>
                    <p class="{{ $label }}">{{ __('Planned') }}</p>
                    <p class="mt-1 font-data text-sm tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity((string) $wo->quantity_target) }} {{ $unit }}</p>
                </div>
                <div>
                    <p class="{{ $label }}">{{ __('Good') }}</p>
                    <p class="mt-1 font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $result->quantity_good) }} {{ $unit }}</p>
                    <p class="font-data text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400">{{ $this->formatSigned($this->difference((string) $wo->quantity_target, (string) $result->quantity_good)) }}</p>
                </div>
                <div>
                    <p class="{{ $label }}">{{ __('Reject') }}</p>
                    <p class="mt-1 font-data text-sm tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity((string) $result->quantity_reject) }} {{ $unit }}</p>
                </div>
            </div>
            @if ($result->reject_reason)
                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Reject reason') }}: “{{ $result->reject_reason }}”</p>
            @endif
            @if ($result->notes)
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Notes') }}: “{{ $result->notes }}”</p>
            @endif
        </div>

        <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 p-5">
            <h2 class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Machine & Overhead') }}</h2>
            <div class="mt-3 grid grid-cols-3 gap-3">
                <div>
                    <p class="{{ $label }}">{{ __('Planned') }}</p>
                    <p class="mt-1 font-data text-sm tabular-nums text-zinc-700 dark:text-zinc-300">{{ $this->formatQuantity((string) $wo->planned_machine_hours) }} {{ __('hrs') }}</p>
                </div>
                <div>
                    <p class="{{ $label }}">{{ __('Actual') }}</p>
                    <p class="mt-1 font-data text-sm font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatQuantity((string) $result->machine_hours) }} {{ __('hrs') }}</p>
                    <p class="font-data text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400">{{ $this->formatSigned($this->difference((string) $wo->planned_machine_hours, (string) $result->machine_hours)) }}</p>
                </div>
                <div>
                    <p class="{{ $label }}">{{ __('Rate / Machine Hour') }}</p>
                    <p class="mt-1 font-data text-sm tabular-nums text-zinc-500 dark:text-zinc-400">{{ $this->formatRupiah((string) $wo->overhead_rate) }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Workers --}}
    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 80ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">{{ __('Workers') }}</h2>
        <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Worker') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Planned') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Actual') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Difference') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Rate / Hour') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Cost') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->workerRows as $row)
                        <tr wire:key="w-{{ $row['employee']->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                            <td class="py-2 px-3">
                                <p class="text-zinc-800 dark:text-zinc-200">
                                    {{ $row['employee']->name }}
                                    @if ($row['planned'] === null)
                                        <span class="ml-1 inline-flex items-center rounded-full border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 px-1.5 py-px text-[10px] font-medium text-amber-700 dark:text-amber-400">{{ __('Not planned') }}</span>
                                    @endif
                                </p>
                                <p class="font-data text-[11px] text-accent">{{ $row['employee']->employee_code }}</p>
                            </td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $row['planned'] === null ? '—' : $this->formatQuantity($row['planned']).' '.__('hrs') }}</td>
                            <td class="py-2 px-3 text-right font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatQuantity($row['actual']) }} {{ __('hrs') }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ $this->formatSigned($this->difference($row['planned'], $row['actual'])) }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ $row['rate'] !== null ? $this->formatRupiah((string) $row['rate']) : '—' }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $row['cost'] !== null ? $this->formatRupiah((string) $row['cost']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Materials --}}
    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 100ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">{{ __('Materials') }}</h2>
        <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Material') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Planned') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Issued') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Used') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('vs plan') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Leftover') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Cost') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->materialRows as $row)
                        @php($p = $row['product'])
                        <tr wire:key="m-{{ $p->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                            <td class="py-2 px-3">
                                <p class="text-zinc-800 dark:text-zinc-200">
                                    {{ $p->product_name }}
                                    @if ($row['planned'] === null)
                                        <span class="ml-1 inline-flex items-center rounded-full border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/10 px-1.5 py-px text-[10px] font-medium text-amber-700 dark:text-amber-400">{{ __('Outside plan') }}</span>
                                    @endif
                                </p>
                                <p class="font-data text-[11px] text-accent">{{ $p->product_code }}</p>
                            </td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $row['planned'] === null ? '—' : $this->formatQuantity($row['planned']).' '.$p->unit_of_measure }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ $this->formatQuantity($row['issued']) }} {{ $p->unit_of_measure }}</td>
                            <td class="py-2 px-3 text-right font-data font-medium tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->formatQuantity($row['used']) }} {{ $p->unit_of_measure }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-500 dark:text-zinc-400 whitespace-nowrap">{{ $this->formatSigned($this->difference($row['planned'], $row['used'])) }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap {{ bccomp($row['leftover'], '0', 2) > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $this->formatQuantity($row['leftover']) }} {{ $p->unit_of_measure }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-900 dark:text-white whitespace-nowrap">{{ $posted ? $this->formatRupiah($row['cost']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-8 text-center text-zinc-500 dark:text-zinc-400">{{ __('No materials were issued to this work order.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->materialRows->contains(fn ($row) => bccomp($row['leftover'], '0', 2) > 0))
            <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">{{ __('Leftover material goes back to the warehouse with a material return. Its cost is not charged to this work order.') }}</p>
        @endif
    </div>

    {{-- Cost summary --}}
    <div class="mt-8 motion-safe:animate-fade-slide-up" style="animation-delay: 120ms;">
        <h2 class="text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300 mb-3">{{ __('Production Cost') }}</h2>
        @if ($posted)
            <div class="rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 overflow-hidden">
                <dl class="divide-y divide-zinc-100 dark:divide-white/5 text-sm">
                    @foreach ([
                        [__('Material'), (string) $result->material_cost, null],
                        [__('Labor'), (string) $result->labor_cost, $this->plannedLaborCost],
                        [__('Overhead'), (string) $result->overhead_cost, (string) $wo->planned_overhead_cost],
                    ] as [$name, $actual, $planned])
                        <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                            <dt class="text-zinc-600 dark:text-zinc-400">{{ $name }}</dt>
                            <dd class="text-right">
                                <span class="font-data tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($actual) }}</span>
                                @if ($planned !== null)
                                    <span class="block font-data text-[11px] tabular-nums text-zinc-500 dark:text-zinc-400">{{ __('Planned') }} {{ $this->formatRupiah($planned) }}</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5 bg-zinc-50 dark:bg-white/5">
                        <dt class="font-medium text-zinc-800 dark:text-zinc-200">{{ __('Total') }}</dt>
                        <dd class="font-data font-medium tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah((string) $result->total_cost) }}</dd>
                    </div>
                </dl>
                <div class="px-4 py-3.5 bg-accent">
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-accent-foreground/70">{{ __('Unit cost') }} · {{ $this->formatRupiah((string) $result->total_cost) }} ÷ {{ $this->formatQuantity((string) $result->quantity_good) }} {{ $unit }}</p>
                    <p class="font-data text-xl font-medium tabular-nums text-accent-foreground mt-0.5">{{ $this->formatRupiah((string) $result->unit_cost) }}/{{ $unit }}</p>
                </div>
            </div>
        @else
            <div class="rounded-md border border-dashed border-zinc-200 dark:border-white/10 py-6 px-4 text-center text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Material, labor and overhead cost — and the cost per unit — are calculated when this result is posted.') }}
            </div>
        @endif
    </div>

    @can('post', $result)
        <flux:modal name="post-result" focusable class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Post this production result?') }}</flux:heading>
                    <flux:subheading>{{ __('The work order becomes Completed and its actual cost is fixed. A posted result can no longer be changed.') }}</flux:subheading>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button variant="primary" wire:click="post" wire:loading.attr="disabled" wire:target="post" class="active:scale-[0.95]">{{ __('Post') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan
</section>
