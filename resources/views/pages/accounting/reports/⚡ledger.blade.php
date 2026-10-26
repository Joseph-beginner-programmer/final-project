<?php

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Support\Reports\GeneralLedgerReport;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('General Ledger')] class extends Component {
    #[Url]
    public string $period = '';

    #[Url]
    public string $account = '';

    public function mount(): void
    {
        Gate::authorize('reports.finance');
        $this->period = ReportPeriod::normalize($this->period);

        if (! Account::whereKey((int) $this->account)->exists()) {
            $first = JournalEntryLine::query()->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')->orderBy('accounts.account_code')->value('accounts.id');
            $this->account = $first ? (string) $first : '';
        }
    }

    public function updatedPeriod(): void
    {
        $this->period = ReportPeriod::normalize($this->period);
    }

    public function shiftPeriod(int $months): void
    {
        $this->period = ReportPeriod::start($this->period)->addMonthsNoOverflow($months)->format('Y-m');
    }

    #[Computed]
    public function accountOptions(): array
    {
        return Account::orderBy('account_code')->get()->map(fn (Account $a) => [
            'id' => (string) $a->id,
            'title' => $a->account_name,
            'subtitle' => $a->account_code,
            'meta' => $a->type->label().($a->is_active ? '' : ' · '.__('Inactive')),
            'disabled_reason' => null,
        ])->all();
    }

    #[Computed]
    public function selected(): ?Account
    {
        return filled($this->account) ? Account::find((int) $this->account) : null;
    }

    #[Computed]
    public function report(): ?GeneralLedgerReport
    {
        return $this->selected ? new GeneralLedgerReport($this->selected, ReportPeriod::start($this->period), ReportPeriod::end($this->period)) : null;
    }

    #[Computed]
    public function rows(): Collection
    {
        return $this->report?->rows() ?? collect();
    }

    #[Computed]
    public function summary(): ?array
    {
        return $this->report?->summary($this->rows);
    }

    public function amount(string $value): string
    {
        return bccomp($value, '0', 2) === 0 ? '' : number_format((float) $value, 0, ',', '.');
    }

    public function balance(string $value): string
    {
        return ((float) $value < 0 ? '(' : '').number_format(abs((float) $value), 0, ',', '.').((float) $value < 0 ? ')' : '');
    }

    public function rupiah(string $value): string
    {
        return ((float) $value < 0 ? '(Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.').((float) $value < 0 ? ')' : '');
    }
}; ?>

@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 whitespace-nowrap')
@php($num = 'py-2.5 px-3 text-right font-data tabular-nums whitespace-nowrap')
@php($acc = $this->selected)
@php($s = $this->summary)
@php($exportParams = ['period' => $period, 'account' => $account])

<section class="w-full max-w-6xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route(auth()->user()->role->reportsRoute())" wire:navigate>{{ __('Reports') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('General Ledger') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('General Ledger') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Every journal line of one account in the month, with the running balance.') }}</p>
        </div>
        @if ($acc)
            <div class="flex items-center gap-2">
                <flux:button size="sm" variant="filled" icon="document-arrow-down" :href="route('accounting.reports.ledger.export', ['format' => 'pdf', ...$exportParams])" class="active:scale-[0.95]">PDF</flux:button>
                <flux:button size="sm" variant="filled" icon="table-cells" :href="route('accounting.reports.ledger.export', ['format' => 'csv', ...$exportParams])" class="active:scale-[0.95]">CSV</flux:button>
            </div>
        @endif
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-3">
        <div class="w-80">
            <x-picker model="account" size="sm" :options="$this->accountOptions" :selected="$account" :placeholder="__('Choose account...')" :label="__('Account')" />
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400 me-1">{{ __('Period') }}</span>
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="shiftPeriod(-1)" :aria-label="__('Previous month')" class="active:scale-[0.95]" />
            <div class="w-44">
                <flux:input size="sm" type="month" wire:model.live="period" :aria-label="__('Period')" />
            </div>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="shiftPeriod(1)" :aria-label="__('Next month')" class="active:scale-[0.95]" />
        </div>
    </div>

    @if (! $acc)
        <div class="mt-6 rounded-xl border border-dashed border-zinc-300 dark:border-white/10 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('Choose an account to see its ledger.') }}</div>
    @else
        <div wire:loading.class="opacity-50" wire:target="period,account,shiftPeriod" class="transition-opacity">
            <div class="mt-5 flex flex-wrap items-center gap-2 text-xs text-zinc-600 dark:text-zinc-300">
                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $acc->type->badgeClasses() }}">{{ $acc->type->label() }}</span>
                @if ($acc->is_contra)
                    <span class="inline-flex items-center rounded-full border border-zinc-300 dark:border-white/20 px-2 py-0.5 text-[11px] text-zinc-600 dark:text-zinc-300">{{ __('Contra') }}</span>
                @endif
                <span>{{ __('Normal balance: :side. A figure in brackets is on the opposite side.', ['side' => $acc->normal_balance->label()]) }}</span>
            </div>

            <div class="mt-4 grid grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach ([
                    [__('Opening balance'), $this->rupiah($s['opening'])],
                    [__('Debit movement'), $this->rupiah($s['debit'])],
                    [__('Credit movement'), $this->rupiah($s['credit'])],
                    [__('Closing balance'), $this->rupiah($s['closing'])],
                ] as [$label, $value])
                    <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-4">
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                        <p class="mt-2 text-lg font-semibold text-zinc-900 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-6 overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
                <table class="w-full min-w-[52rem] text-xs border-collapse">
                    <thead>
                        <tr class="bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                            <th class="{{ $th }} text-left">{{ __('Date') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Journal') }}</th>
                            <th class="{{ $th }} text-left">{{ __('Description') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Debit') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Credit') }}</th>
                            <th class="{{ $th }} text-right">{{ __('Running balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-zinc-100 dark:border-white/5 bg-zinc-50/60 dark:bg-white/[0.02]">
                            <td colspan="5" class="py-2 px-3 text-zinc-500 dark:text-zinc-400">{{ __('Opening balance') }}</td>
                            <td class="{{ $num }} font-medium text-zinc-900 dark:text-white">{{ $this->balance($s['opening']) }}</td>
                        </tr>
                        @forelse ($this->rows as $i => $row)
                            <tr wire:key="gl-{{ $i }}" class="border-b border-zinc-100 dark:border-white/5 align-top hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] {{ $row['tag'] ? 'text-zinc-500 dark:text-zinc-400' : '' }}">
                                <td class="py-2.5 px-3 whitespace-nowrap text-zinc-700 dark:text-zinc-300">{{ $row['date']->translatedFormat('d M Y') }}</td>
                                <td class="py-2.5 px-3 whitespace-nowrap"><a href="{{ route('accounting.journal.show', $row['journal_id']) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $row['journal_number'] }}</a></td>
                                <td class="py-2.5 px-3">
                                    <p class="text-zinc-800 dark:text-zinc-200">
                                        {{ $row['description'] }}
                                        @if ($row['tag'] === 'reversed')
                                            <span class="ml-1 inline-flex rounded-full border border-zinc-300 dark:border-white/20 px-1.5 py-0.5 text-[10px] line-through">{{ __('Reversed') }}</span>
                                        @elseif ($row['tag'] === 'reversal')
                                            <span class="ml-1 inline-flex rounded-full border border-zinc-300 dark:border-white/20 px-1.5 py-0.5 text-[10px]">{{ __('Reversal') }}</span>
                                        @endif
                                    </p>
                                    @if ($row['counter'] !== '')
                                        <p class="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('vs :accounts', ['accounts' => $row['counter']]) }}</p>
                                    @endif
                                </td>
                                <td class="{{ $num }} text-zinc-800 dark:text-zinc-200">{{ $this->amount($row['debit']) }}</td>
                                <td class="{{ $num }} text-zinc-800 dark:text-zinc-200">{{ $this->amount($row['credit']) }}</td>
                                <td class="{{ $num }} {{ (float) $row['balance'] < 0 ? 'text-amber-700 dark:text-amber-400' : 'text-zinc-900 dark:text-white' }}">{{ $this->balance($row['balance']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No movements in this period.') }}</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-zinc-300 dark:border-white/20 bg-zinc-50 dark:bg-white/5">
                            <td colspan="3" class="py-2.5 px-3 font-semibold text-zinc-900 dark:text-white">{{ __('Closing balance') }}</td>
                            <td class="{{ $num }} font-semibold text-zinc-900 dark:text-white">{{ number_format((float) $s['debit'], 0, ',', '.') }}</td>
                            <td class="{{ $num }} font-semibold text-zinc-900 dark:text-white">{{ number_format((float) $s['credit'], 0, ',', '.') }}</td>
                            <td class="{{ $num }} font-semibold text-zinc-900 dark:text-white">{{ $this->balance($s['closing']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endif
</section>
