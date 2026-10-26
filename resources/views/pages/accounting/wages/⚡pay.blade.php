<?php

use App\Actions\Accounting\PayWagesAction;
use App\Actions\Sales\RecordSalesPaymentAction;
use App\Enums\AccountType;
use App\Exceptions\InvalidJournalEntryException;
use App\Exceptions\InvalidWagePaymentException;
use App\Models\Account;
use App\Support\JournalPreview;
use App\Support\UnpaidWages;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pay Wages')] class extends Component {
    public string $periodEnd = '';

    public string $paymentDate = '';

    public string $cashAccountId = '';

    public string $reference = '';

    /** @var list<string> */
    public array $selected = [];

    public function mount(): void
    {
        Gate::authorize('wages.pay');
        $this->periodEnd = now()->toDateString();
        $this->paymentDate = now()->toDateString();
        $this->selectAll();
    }

    public function updatedPeriodEnd(): void
    {
        unset($this->rows);
        $this->selectAll();
    }

    public function selectAll(): void
    {
        $this->selected = $this->rows->map(fn (array $row) => (string) $row['employee']->id)->all();
    }

    public function selectNone(): void
    {
        $this->selected = [];
    }

    protected function until(): ?Carbon
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->periodEnd) === 1 ? Carbon::parse($this->periodEnd) : null;
    }

    #[Computed]
    public function rows(): Collection
    {
        $until = $this->until();

        return $until ? UnpaidWages::perEmployee($until) : collect();
    }

    #[Computed]
    public function total(): string
    {
        return $this->rows
            ->filter(fn (array $row) => in_array((string) $row['employee']->id, $this->selected, true))
            ->reduce(fn (string $carry, array $row) => bcadd($carry, $row['amount'], 2), '0');
    }

    #[Computed]
    public function cashAccounts(): array
    {
        $reserved = array_map(fn ($role) => $role->value, RecordSalesPaymentAction::reservedRoles());

        return Account::active()
            ->where('type', AccountType::Asset->value)
            ->where(fn ($q) => $q->whereNull('system_role')->orWhereNotIn('system_role', $reserved))
            ->orderBy('account_code')
            ->get()
            ->map(fn (Account $a) => ['id' => (string) $a->id, 'title' => $a->account_name, 'subtitle' => $a->account_code, 'meta' => null, 'disabled_reason' => null])
            ->all();
    }

    #[Computed]
    public function preview(): array
    {
        return JournalPreview::resolve(PayWagesAction::plan($this->total, filled($this->cashAccountId) ? (int) $this->cashAccountId : null), ['debit', 'credit'], __('Choose a cash or bank account'));
    }

    protected function check(): void
    {
        $this->validate([
            'periodEnd' => ['required', 'date', 'before_or_equal:paymentDate'],
            'paymentDate' => ['required', 'date', 'before_or_equal:today'],
            'cashAccountId' => ['required', 'exists:accounts,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'selected' => ['required', 'array', 'min:1'],
        ], [
            'selected.required' => __('Choose at least one employee to pay.'),
            'selected.min' => __('Choose at least one employee to pay.'),
        ], [
            'periodEnd' => __('Work up to'),
            'paymentDate' => __('Payment date'),
            'cashAccountId' => __('Paid from'),
            'reference' => __('Reference'),
        ]);
    }

    public function review(): void
    {
        Gate::authorize('wages.pay');
        $this->check();
        $this->modal('confirm-wages')->show();
    }

    public function pay(): void
    {
        Gate::authorize('wages.pay');
        $this->check();

        try {
            $payment = app(PayWagesAction::class)->handle(
                $this->periodEnd,
                $this->paymentDate,
                (int) $this->cashAccountId,
                array_map('intval', $this->selected),
                $this->reference,
                Auth::id(),
            );
        } catch (InvalidWagePaymentException|InvalidJournalEntryException $e) {
            Flux::toast(variant: 'danger', text: $e->userMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Wage payment :number posted.', ['number' => $payment->payment_number]));
        $this->redirectRoute('accounting.wages.show', $payment, navigate: true);
    }

    public function rupiah(string|float $value): string
    {
        return ((float) $value < 0 ? '−Rp ' : 'Rp ').number_format(abs((float) $value), 0, ',', '.');
    }

    public function hours(string|float $value): string
    {
        return number_format((float) $value, (float) $value == floor((float) $value) ? 0 : 1, ',', '.');
    }
}; ?>

@php($th = 'py-2 px-3 text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400')
@php($rows = $this->rows)

<section class="w-full max-w-5xl mx-auto">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('accounting.wages.index')" wire:navigate>{{ __('Wage Payments') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Pay wages') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3">
        <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Pay wages') }}</h1>
        <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Everything each chosen employee earned up to the cut-off date and has not been paid yet.') }}</p>
    </div>

    <form wire:submit="review" class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 min-w-0 space-y-4">
            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:input type="date" wire:model.live="periodEnd" :label="__('Work up to')" :max="now()->toDateString()" />
                    <flux:input type="date" wire:model="paymentDate" :label="__('Payment date')" :max="now()->toDateString()" />
                </div>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Hours from production results on or before this date.') }}</p>
            </div>

            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 dark:border-white/10 px-4 py-2.5">
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Employees') }}</p>
                    @if ($rows->isNotEmpty())
                        <div class="flex items-center gap-3 text-xs">
                            <button type="button" wire:click="selectAll" class="font-medium text-accent hover:underline underline-offset-2 cursor-pointer">{{ __('Select all') }}</button>
                            <button type="button" wire:click="selectNone" class="font-medium text-zinc-500 hover:underline underline-offset-2 cursor-pointer">{{ __('Clear') }}</button>
                        </div>
                    @endif
                </div>
                <div wire:loading.class="opacity-50" wire:target="periodEnd" class="transition-opacity">
                    <table class="w-full min-w-[34rem] text-xs border-collapse">
                        <thead>
                            <tr class="text-left bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                                <th class="{{ $th }} w-10"></th>
                                <th class="{{ $th }}">{{ __('Employee') }}</th>
                                <th class="{{ $th }}">{{ __('Work dates') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Hours') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Wages') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                @php($id = (string) $row['employee']->id)
                                <tr wire:key="emp-{{ $id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 {{ in_array($id, $selected, true) ? '' : 'opacity-50' }}">
                                    <td class="py-2.5 px-3"><flux:checkbox wire:model.live="selected" value="{{ $id }}" :aria-label="$row['employee']->name" /></td>
                                    <td class="py-2.5 px-3 font-medium text-zinc-900 dark:text-white">{{ $row['employee']->name }}</td>
                                    <td class="py-2.5 px-3 text-zinc-600 dark:text-zinc-300">
                                        {{ $row['from']->isSameDay($row['to']) ? $row['from']->translatedFormat('d M Y') : $row['from']->translatedFormat('d M').' – '.$row['to']->translatedFormat('d M Y') }}
                                        <span class="text-zinc-400 dark:text-zinc-500">· {{ trans_choice(':count result|:count results', $row['results'], ['count' => $row['results']]) }}</span>
                                    </td>
                                    <td class="py-2.5 px-3 text-right font-data tabular-nums text-zinc-800 dark:text-zinc-200">{{ $this->hours($row['hours']) }}</td>
                                    <td class="py-2.5 px-3 text-right font-data tabular-nums font-medium text-zinc-900 dark:text-white whitespace-nowrap">{{ $this->rupiah($row['amount']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No unpaid wages up to this date.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @error('selected') <p class="border-t border-zinc-100 dark:border-white/5 px-4 py-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-zinc-200 dark:border-white/10 bg-white dark:bg-zinc-900 p-5 space-y-4">
                <div>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('To pay') }}</p>
                    <p class="mt-1 font-data text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->rupiah($this->total) }}</p>
                    <p class="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count employee|:count employees', count($selected), ['count' => count($selected)]) }}</p>
                </div>
                <flux:field>
                    <flux:label>{{ __('Paid from') }}</flux:label>
                    <x-picker model="cashAccountId" size="md" :options="$this->cashAccounts" :selected="$cashAccountId" :placeholder="__('Cash or bank account...')" :label="__('Paid from')" :invalid="$errors->has('cashAccountId')" />
                    @error('cashAccountId') <flux:error class="mt-1" :message="$message" /> @enderror
                </flux:field>
                <flux:input wire:model="reference" :label="__('Reference')" :badge="__('Optional')" :placeholder="__('e.g. transfer number')" />
                <flux:button type="submit" variant="primary" icon="banknotes" class="w-full" :disabled="$rows->isEmpty()">{{ __('Review payment') }}</flux:button>
            </div>
        </div>
    </form>

    <flux:modal name="confirm-wages" class="max-w-xl">
        @php($preview = $this->preview)
        @php($unmapped = collect($preview)->where('unmapped', true))
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Pay :amount in wages?', ['amount' => $this->rupiah($this->total)]) }}</flux:heading>
                <flux:subheading>{{ __('For work up to :until, posted on :date.', ['until' => $periodEnd ? Carbon::parse($periodEnd)->translatedFormat('d M Y') : '', 'date' => $paymentDate ? Carbon::parse($paymentDate)->translatedFormat('d M Y') : '']) }}</flux:subheading>
            </div>
            <x-journal-preview :lines="$preview" />
            @if ($unmapped->isNotEmpty())
                <p class="flex items-start gap-1.5 rounded-lg bg-amber-50 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
                    <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-3.5 shrink-0" />
                    {{ __('Map these system roles on the Chart of Accounts page before posting: :roles.', ['roles' => $unmapped->pluck('name')->implode(', ')]) }}
                </p>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Back') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="pay" wire:loading.attr="disabled" wire:target="pay" :disabled="$unmapped->isNotEmpty()">{{ __('Pay wages') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
