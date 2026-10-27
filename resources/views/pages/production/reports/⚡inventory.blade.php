<?php

use App\Enums\Direction;
use App\Enums\ProductType;
use App\Support\Reports\InventoryReport;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Warehouse Inventory Report')] class extends Component {
    #[Url]
    public string $period = '';

    #[Url]
    public string $type = '';

    public function mount(): void
    {
        Gate::authorize('reports.production');
        $this->normalize();
    }

    public function updated(): void
    {
        $this->normalize();
    }

    protected function normalize(): void
    {
        $this->period = ReportPeriod::normalize($this->period);
        $this->type = ReportPeriod::type($this->type, InventoryReport::allTypes())?->value ?? '';
    }

    public function shiftPeriod(int $months): void
    {
        $this->period = ReportPeriod::start($this->period)->addMonthsNoOverflow($months)->format('Y-m');
    }

    #[Computed]
    public function showValue(): bool
    {
        return Gate::allows('reports.inventory-value');
    }

    #[Computed]
    public function report(): InventoryReport
    {
        return new InventoryReport(ReportPeriod::start($this->period), ReportPeriod::end($this->period), ProductType::tryFrom($this->type));
    }

    #[Computed]
    public function rows(): Collection
    {
        return $this->report->rows();
    }

    #[Computed]
    public function valueTotals(): array
    {
        return $this->report->valueTotals($this->rows);
    }

    public function quantity(string $value): string
    {
        $formatted = number_format((float) $value, 2, ',', '.');

        return str_ends_with($formatted, ',00') ? substr($formatted, 0, -3) : $formatted;
    }

    public function rupiah(string $value): string
    {
        return ((float) $value < 0 ? '−Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.');
    }
}; ?>

@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 whitespace-nowrap')
@php($num = 'py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap')
@php($ink = 'text-zinc-800 dark:text-zinc-200')
@php($value = 'py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-500 dark:text-zinc-400')
@php($exportParams = ['period' => $period, 'type' => $type ?: null])
@php($cols = $this->showValue ? 11 : 7)
@php($periodStart = \App\Support\Reports\ReportPeriod::start($period))

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route(auth()->user()->role->reportsRoute())" wire:navigate>{{ __('Reports') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Warehouse Inventory Report') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Warehouse Inventory Report') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Raw materials, WIP and finished goods: stock at the start of the month, what came in, what went out, and what is left.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="filled" icon="document-arrow-down" :href="route('production.reports.inventory.export', ['format' => 'pdf', ...$exportParams])" class="active:scale-[0.95]">PDF</flux:button>
            <flux:button size="sm" variant="filled" icon="table-cells" :href="route('production.reports.inventory.export', ['format' => 'csv', ...$exportParams])" class="active:scale-[0.95]">CSV</flux:button>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400 me-1">{{ __('Period') }}</span>
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="shiftPeriod(-1)" :aria-label="__('Previous month')" class="active:scale-[0.95]" />
            <div class="w-44">
                <flux:input size="sm" type="month" wire:model.live="period" :aria-label="__('Period')" />
            </div>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="shiftPeriod(1)" :aria-label="__('Next month')" class="active:scale-[0.95]" />
        </div>
        <div class="w-full sm:w-[28rem]">
            <flux:radio.group wire:model.live="type" variant="segmented" size="sm" :aria-label="__('Product type')">
                <flux:radio value="" :label="__('All')" />
                <flux:radio value="raw_material" :label="__('Raw Material')" />
                <flux:radio value="wip" :label="__('WIP')" />
                <flux:radio value="finished_goods" :label="__('Finished goods')" />
            </flux:radio.group>
        </div>
    </div>

    <div wire:loading.class="opacity-50" wire:target="period,type,shiftPeriod" class="transition-opacity">
        @if ($this->showValue)
            @php($v = $this->valueTotals)
            <div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach ([
                    [__('Opening value'), $v['opening_value'], __('on :date', ['date' => $periodStart->translatedFormat('j M Y')])],
                    [__('Value in'), $v['in_value'], __('received this month')],
                    [__('Value out'), $v['out_value'], __('used or shipped this month')],
                    [__('Closing value'), $v['closing_value'], __('on :date', ['date' => $periodStart->copy()->endOfMonth()->translatedFormat('j M Y')])],
                ] as [$label, $amount, $note])
                    <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-4">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                        <p class="mt-2 text-xl font-semibold text-zinc-900 dark:text-white">{{ $this->rupiah($amount) }}</p>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $note }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="mt-6 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full {{ $this->showValue ? 'min-w-[72rem]' : 'min-w-[44rem]' }} text-xs border-collapse">
                <thead class="bg-zinc-50 dark:bg-white/5">
                    @if ($this->showValue)
                        <tr>
                            <th colspan="3"></th>
                            <th colspan="4" class="py-1.5 px-3 text-center text-[10px] font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-300 border-b border-zinc-200 dark:border-white/10">{{ __('Quantity') }}</th>
                            <th colspan="4" class="py-1.5 px-3 text-center text-[10px] font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-300 border-b border-s border-zinc-200 dark:border-white/10">{{ __('Value (FIFO)') }}</th>
                        </tr>
                    @endif
                    <tr class="text-left border-b border-zinc-200 dark:border-white/10">
                        <th class="{{ $th }}">{{ __('Item') }}</th>
                        <th class="{{ $th }}">{{ __('Type') }}</th>
                        <th class="{{ $th }}">{{ __('Unit') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Opening') }}</th>
                        <th class="{{ $th }} text-right">{{ __('In') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Out') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Closing') }}</th>
                        @if ($this->showValue)
                            <th class="{{ $th }} text-right border-s border-zinc-200 dark:border-white/10">{{ __('Opening') }}</th>
                            <th class="{{ $th }} text-right">{{ __('In') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Out') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Closing') }}</th>
                        @endif
                    </tr>
                </thead>
                @forelse ($this->rows as $r)
                    @php($p = $r['product'])
                    @php($hasMoves = $r['breakdown']->isNotEmpty())
                    <tbody wire:key="inv-{{ $p->id }}" x-data="{ open: false }" class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                        <tr class="hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] {{ $hasMoves ? 'cursor-pointer' : '' }}" @if ($hasMoves) x-on:click="open = ! open" x-on:keydown.enter="open = ! open" tabindex="0" :aria-expanded="open" @endif>
                            <td class="py-2.5 px-3">
                                <div class="flex items-center gap-2">
                                    @if ($hasMoves)
                                        <flux:icon.chevron-right variant="micro" class="size-3.5 shrink-0 text-zinc-400 transition-transform" x-bind:class="open && 'rotate-90'" />
                                    @else
                                        <span class="w-3.5 shrink-0"></span>
                                    @endif
                                    <div>
                                        <p class="text-zinc-900 dark:text-white">{{ $p->product_name }}</p>
                                        <p class="font-data text-[11px] text-zinc-500 dark:text-zinc-400">{{ $p->product_code }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-2.5 px-3"><span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap {{ $p->type->badgeClasses() }}">{{ $p->type->label() }}</span></td>
                            <td class="py-2.5 px-3 text-zinc-600 dark:text-zinc-400">{{ $p->unit_of_measure }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->quantity($r['opening_qty']) }}</td>
                            <td class="{{ $num }} {{ bccomp($r['in_qty'], '0', 2) > 0 ? 'text-green-700 dark:text-green-400' : 'text-zinc-400 dark:text-zinc-500' }}">{{ bccomp($r['in_qty'], '0', 2) > 0 ? '+' : '' }}{{ $this->quantity($r['in_qty']) }}</td>
                            <td class="{{ $num }} {{ bccomp($r['out_qty'], '0', 2) > 0 ? '' : 'text-zinc-400 dark:text-zinc-500' }}">{{ bccomp($r['out_qty'], '0', 2) > 0 ? '−' : '' }}{{ $this->quantity($r['out_qty']) }}</td>
                            <td class="{{ $num }} {{ $ink }} font-semibold">{{ $this->quantity($r['closing_qty']) }}</td>
                            @if ($this->showValue)
                                <td class="{{ $value }} border-s border-zinc-100 dark:border-white/5">{{ $this->rupiah($r['opening_value']) }}</td>
                                <td class="{{ $value }}">{{ $this->rupiah($r['in_value']) }}</td>
                                <td class="{{ $value }}">{{ $this->rupiah($r['out_value']) }}</td>
                                <td class="{{ $num }} {{ $ink }} font-semibold">{{ $this->rupiah($r['closing_value']) }}</td>
                            @endif
                        </tr>
                        @if ($hasMoves)
                            <tr x-show="open" x-cloak class="bg-zinc-50/70 dark:bg-white/[0.02]">
                                <td colspan="{{ $cols }}" class="px-3 pb-3 pt-1">
                                    <div class="ms-5.5 grid gap-1 sm:max-w-xl">
                                        @foreach ($r['breakdown'] as $b)
                                            @php($in = $b['direction'] === Direction::In)
                                            <div class="flex items-center justify-between gap-4 text-[11px]">
                                                <span class="text-zinc-600 dark:text-zinc-300">{{ $in ? '+' : '−' }} {{ $b['type']->label() }}</span>
                                                <span class="font-data tabular-nums text-zinc-800 dark:text-zinc-200">
                                                    {{ $in ? '+' : '−' }}{{ $this->quantity($b['qty']) }} {{ $p->unit_of_measure }}
                                                    @if ($this->showValue)<span class="ms-2 text-zinc-500 dark:text-zinc-400">{{ $this->rupiah($b['value']) }}</span>@endif
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                @empty
                    <tbody>
                        <tr><td colspan="{{ $cols }}" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No items for this filter.') }}</td></tr>
                    </tbody>
                @endforelse
                @if ($this->showValue && $this->rows->isNotEmpty())
                    <tfoot>
                        <tr class="border-t-2 border-zinc-300 dark:border-white/20 bg-zinc-50 dark:bg-white/5 font-semibold">
                            <td colspan="7" class="py-2.5 px-3 text-zinc-900 dark:text-white">{{ __('Total value') }}</td>
                            <td class="{{ $num }} {{ $ink }} border-s border-zinc-200 dark:border-white/10">{{ $this->rupiah($v['opening_value']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($v['in_value']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($v['out_value']) }}</td>
                            <td class="{{ $num }} {{ $ink }}">{{ $this->rupiah($v['closing_value']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <p class="mt-2 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Closing = opening + in − out, from the stock movement records. Click an item to see what the movements were.') }}@if (! $this->showValue) {{ __('Values in Rupiah are visible to Manager and Accounting.') }}@endif</p>
    </div>
</section>
