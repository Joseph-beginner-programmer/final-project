<?php

use App\Enums\PurchaseOrderStatus;
use App\Support\Reports\PurchaseOrderReport;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Purchase Order Report')] class extends Component {
    #[Url]
    public string $period = '';

    public function mount(): void
    {
        Gate::authorize('reports.purchasing');
        $this->normalize();
    }

    public function updated(): void
    {
        $this->normalize();
    }

    protected function normalize(): void
    {
        $this->period = ReportPeriod::normalize($this->period);
    }

    public function shiftPeriod(int $months): void
    {
        $this->period = ReportPeriod::start($this->period)->addMonthsNoOverflow($months)->format('Y-m');
    }

    #[Computed]
    public function report(): PurchaseOrderReport
    {
        return new PurchaseOrderReport(ReportPeriod::start($this->period), ReportPeriod::end($this->period));
    }

    #[Computed]
    public function rows(): Collection
    {
        return $this->report->rows();
    }

    #[Computed]
    public function totals(): array
    {
        return $this->report->sum($this->rows);
    }

    #[Computed]
    public function statuses(): Collection
    {
        return $this->report->statusSummary($this->rows);
    }

    public function rupiah(string|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return ((float) $value < 0 ? '−Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.');
    }

    public function percent(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 1, ',', '.').'%';
    }

    public function statusBadgeClasses(PurchaseOrderStatus $status): string
    {
        return match ($status) {
            PurchaseOrderStatus::Draft => 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:border-white/10',
            PurchaseOrderStatus::PendingApproval => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20',
            PurchaseOrderStatus::Approved => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-400 dark:border-blue-500/20',
            PurchaseOrderStatus::Rejected => 'bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20',
            PurchaseOrderStatus::PartiallyReceived => 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-500/10 dark:text-purple-400 dark:border-purple-500/20',
            PurchaseOrderStatus::FullyReceived => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
            PurchaseOrderStatus::Cancelled => 'bg-zinc-100 text-zinc-500 border-zinc-200 line-through dark:bg-white/5 dark:text-zinc-500 dark:border-white/10',
            PurchaseOrderStatus::Closed => 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-500/10 dark:text-slate-400 dark:border-slate-500/20',
        };
    }
}; ?>

@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 whitespace-nowrap')
@php($num = 'py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap')
@php($ink = 'text-zinc-800 dark:text-zinc-200')
@php($exportParams = ['period' => $period])
@php($t = $this->totals)

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route(auth()->user()->role->reportsRoute())" wire:navigate>{{ __('Reports') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Purchase Order Report') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Purchase Order Report') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Every purchase order created in the month, how many items each has, its total and current status.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="filled" icon="document-arrow-down" :href="route('purchasing.reports.orders.export', ['format' => 'pdf', ...$exportParams])" class="active:scale-[0.95]">PDF</flux:button>
            <flux:button size="sm" variant="filled" icon="table-cells" :href="route('purchasing.reports.orders.export', ['format' => 'excel', ...$exportParams])" class="active:scale-[0.95]">Excel</flux:button>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-2">
        <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400 me-1">{{ __('Period') }}</span>
        <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="shiftPeriod(-1)" :aria-label="__('Previous month')" class="active:scale-[0.95]" />
        <div class="w-44">
            <flux:input size="sm" type="month" wire:model.live="period" :aria-label="__('Period')" />
        </div>
        <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="shiftPeriod(1)" :aria-label="__('Next month')" class="active:scale-[0.95]" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="period,shiftPeriod" class="transition-opacity">
        <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ([
                [__('Purchase orders'), $t['count'], null],
                [__('Line items'), $t['items_count'], null],
                [__('Total amount'), $this->rupiah($t['total_amount']), null],
                [__('Overdue'), $t['overdue'], __('Past their expected delivery date.')],
            ] as [$label, $value, $note])
                <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-4">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                    <p class="mt-2 text-xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $value }}</p>
                    @if ($note)
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $note }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('By status') }}</h2>
        <div class="mt-3 flex flex-wrap gap-2">
            @forelse ($this->statuses as $s)
                <div class="flex items-center gap-2 rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 px-3 py-2">
                    <span class="rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $this->statusBadgeClasses($s['status']) }}">{{ $s['status']->label() }}</span>
                    <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $s['count'] }}</span>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $this->rupiah($s['total_amount']) }}</span>
                </div>
            @empty
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No purchase orders created in this period.') }}</p>
            @endforelse
        </div>

        <h2 class="mt-8 text-xs font-bold uppercase tracking-widest text-zinc-700 dark:text-zinc-300">{{ __('Purchase orders') }}</h2>
        <div class="mt-3 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full min-w-[65rem] text-xs border-collapse">
                <thead>
                    <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="{{ $th }}">{{ __('PO') }}</th>
                        <th class="{{ $th }}">{{ __('Supplier') }}</th>
                        <th class="{{ $th }}">{{ __('Order date') }}</th>
                        <th class="{{ $th }}">{{ __('Expected delivery') }}</th>
                        <th class="{{ $th }}">{{ __('Status') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Items') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Total amount') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Received') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows as $r)
                        <tr wire:key="po-{{ $r['po']->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03]">
                            <td class="py-2.5 px-3">
                                <a href="{{ route('purchasing.orders.show', $r['po']) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $r['po_number'] }}</a>
                                @if ($r['overdue'])
                                    <span class="ms-1.5 inline-flex items-center gap-0.5 text-[10px] font-medium text-red-700 dark:text-red-400"><flux:icon.exclamation-triangle variant="micro" class="size-3" />{{ __('Overdue') }}</span>
                                @endif
                            </td>
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ $r['supplier'] }}</td>
                            <td class="py-2.5 px-3 whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $r['order_date']->translatedFormat('d M Y') }}</td>
                            <td class="py-2.5 px-3 whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $r['expected_delivery_date']?->translatedFormat('d M Y') ?? '—' }}</td>
                            <td class="py-2.5 px-3"><span class="rounded-full border px-2 py-0.5 text-[11px] {{ $this->statusBadgeClasses($r['status']) }}">{{ $r['status']->label() }}</span></td>
                            <td class="{{ $num }} {{ $ink }}">{{ $r['items_count'] }}</td>
                            <td class="{{ $num }} {{ $ink }} font-semibold">{{ $this->rupiah($r['total_amount']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->percent($r['received_rate']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No purchase orders created in this period.') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($this->rows->isNotEmpty())
                    <tfoot>
                        <tr class="border-t-2 border-zinc-300 dark:border-white/20 bg-zinc-50 dark:bg-white/5 font-semibold">
                            <td class="py-2.5 px-3 text-zinc-900 dark:text-white" colspan="5">{{ __('Total') }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $t['items_count'] }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($t['total_amount']) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <p class="mt-2 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Received = value of goods received so far ÷ total ordered value (at PO price).') }}</p>
    </div>
</section>
