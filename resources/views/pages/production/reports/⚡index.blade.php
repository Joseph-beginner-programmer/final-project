<?php

use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reports')] class extends Component {
    public function mount(): void
    {
        abort_unless(
            Gate::allows('reports.production')
                || Gate::allows('reports.purchasing')
                || Gate::allows('reports.finance')
                || Gate::allows('reports.margin')
                || Gate::allows('reports.receivables')
                || Gate::allows('reports.payables'),
            403
        );
    }

    public function reports(): array
    {
        return [
            ...(Gate::allows('reports.production') ? [
                [
                    'route' => 'production.reports.cost',
                    'icon' => 'calculator',
                    'title' => __('Production Cost Report'),
                    'description' => __('Material, labor and overhead per completed work order, with the cost per unit and a summary per product.'),
                    'scope' => __('Per month'),
                ],
                [
                    'route' => 'production.reports.inventory',
                    'icon' => 'archive-box',
                    'title' => __('Warehouse Inventory Report'),
                    'description' => __('Opening, in, out and closing stock of raw materials, WIP and finished goods for the month.'),
                    'scope' => __('Per month'),
                ],
            ] : []),
            ...(Gate::allows('reports.purchasing') ? [
                [
                    'route' => 'purchasing.reports.orders',
                    'icon' => 'queue-list',
                    'title' => __('Purchase Order Report'),
                    'description' => __('Every purchase order created in the month, how many items each has, its total and current status.'),
                    'scope' => __('Per month'),
                ],
                [
                    'route' => 'purchasing.reports.register',
                    'icon' => 'clipboard-document-list',
                    'title' => __('Purchase Register'),
                    'description' => __('Every purchase invoice posted in the month, with goods, charges, tax, total, paid and balance per supplier.'),
                    'scope' => __('Per month'),
                ],
                [
                    'route' => 'purchasing.reports.price-variance',
                    'icon' => 'scale',
                    'title' => __('Purchase Price Variance Report'),
                    'description' => __('Where the invoiced price differed from the lot value at receipt, per invoice line, with a summary per supplier.'),
                    'scope' => __('Per month'),
                ],
            ] : []),
            ...(Gate::allows('reports.finance') ? [
                [
                    'route' => 'accounting.reports.ledger',
                    'icon' => 'book-open',
                    'title' => __('General Ledger'),
                    'description' => __('Every journal line of one account in the month, with the running balance and the opposite accounts.'),
                    'scope' => __('Per month'),
                ],
                [
                    'route' => 'accounting.reports.trial-balance',
                    'icon' => 'scale',
                    'title' => __('Trial Balance'),
                    'description' => __('Opening balance, movements and closing balance of every account, checking that debit equals credit.'),
                    'scope' => __('Per month'),
                ],
                [
                    'route' => 'accounting.reports.income-statement',
                    'icon' => 'presentation-chart-line',
                    'title' => __('Income Statement'),
                    'description' => __('Revenue, cost of goods sold, gross profit, expenses and net profit, for the month and the year to date.'),
                    'scope' => __('Per month'),
                ],
                [
                    'route' => 'accounting.reports.balance-sheet',
                    'icon' => 'building-library',
                    'title' => __('Balance Sheet'),
                    'description' => __('Assets, liabilities and equity at the end of the month, checking that both sides are equal.'),
                    'scope' => __('Month end'),
                ],
                [
                    'route' => 'accounting.reports.cash-flow',
                    'icon' => 'banknotes',
                    'title' => __('Cash Flow Statement'),
                    'description' => __('Why cash went up or down this month, split into operating and financing activity.'),
                    'scope' => __('Per month'),
                ],
            ] : []),
            ...(Gate::allows('reports.margin') ? [
                [
                    'route' => 'accounting.reports.margin',
                    'icon' => 'chart-bar',
                    'title' => __('Gross Margin Report'),
                    'description' => __('Revenue vs. cost of goods sold on posted deliveries, by product or by customer.'),
                    'scope' => __('Per month'),
                ],
            ] : []),
            ...(Gate::allows('reports.receivables') ? [
                [
                    'route' => 'accounting.reports.receivables',
                    'icon' => 'arrow-down-on-square',
                    'title' => __('Receivables Aging Report'),
                    'description' => __('Piutang Usaha outstanding per customer, split by how overdue each invoice is.'),
                    'scope' => __('As of a date'),
                ],
            ] : []),
            ...(Gate::allows('reports.payables') ? [
                [
                    'route' => 'accounting.reports.payables',
                    'icon' => 'arrow-up-on-square',
                    'title' => __('Payables Aging Report'),
                    'description' => __('Utang Usaha outstanding per supplier, split by how overdue each invoice is.'),
                    'scope' => __('As of a date'),
                ],
            ] : []),
        ];
    }
}; ?>

<section class="w-full max-w-6xl mx-auto">
    <div>
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Reports') }}</h1>
        <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Choose a report. Every report can be exported to PDF and Excel.') }}</p>
    </div>

    <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ($this->reports() as $report)
            <a href="{{ route($report['route']) }}" wire:navigate
                class="group flex flex-col rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5 shadow-sm shadow-zinc-900/5 dark:shadow-none transition hover:border-accent/50 hover:shadow-md focus-visible:outline-2 focus-visible:outline-accent active:scale-[0.99]">
                <div class="flex items-start justify-between gap-3">
                    <span class="inline-flex size-10 items-center justify-center rounded-lg bg-accent/10 text-accent">
                        <flux:icon :name="$report['icon']" class="size-5" />
                    </span>
                    <span class="rounded-full border border-zinc-200 dark:border-white/10 px-2 py-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">{{ $report['scope'] }}</span>
                </div>
                <h2 class="mt-4 text-sm font-semibold text-zinc-900 dark:text-white">{{ $report['title'] }}</h2>
                <p class="mt-1 flex-1 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400">{{ $report['description'] }}</p>
                <span class="mt-4 inline-flex items-center gap-1 text-xs font-medium text-accent">
                    {{ __('Open report') }}
                    <flux:icon.arrow-right variant="micro" class="size-3.5 transition-transform group-hover:translate-x-0.5" />
                </span>
            </a>
        @endforeach
    </div>
</section>
