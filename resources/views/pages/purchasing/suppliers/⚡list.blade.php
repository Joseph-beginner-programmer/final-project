<?php

use App\Actions\MasterData\SaveSupplierAction;
use App\Models\Supplier;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Suppliers')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public bool $showInactive = false;

    public string $supplierName = '';

    public string $contactPerson = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Supplier::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function suppliers(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return Supplier::query()
            ->when(! $this->showInactive, fn (Builder $q) => $q->where('is_active', true))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('supplier_name', 'like', "%{$term}%")
                ->orWhere('supplier_code', 'like', "%{$term}%")
                ->orWhere('contact_person', 'like', "%{$term}%")))
            ->withCount(['products', 'purchaseOrders'])
            ->orderBy('supplier_name')
            ->paginate(15);
    }

    public function openCreate(): void
    {
        Gate::authorize('create', Supplier::class);

        $this->resetValidation();
        $this->reset('supplierName', 'contactPerson', 'phone', 'email', 'address');
        $this->modal('supplier-form')->show();
    }

    protected function rules(): array
    {
        return [
            'supplierName' => ['required', 'string', 'max:255'],
            'contactPerson' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'supplierName' => __('Supplier name'),
            'contactPerson' => __('Contact person'),
            'phone' => __('Phone'),
            'email' => __('Email'),
            'address' => __('Address'),
        ];
    }

    public function save(): void
    {
        Gate::authorize('create', Supplier::class);
        $this->validate();

        $supplier = app(SaveSupplierAction::class)->handle([
            'supplier_name' => $this->supplierName,
            'contact_person' => $this->contactPerson,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'is_active' => true,
        ]);

        Flux::toast(variant: 'success', text: __('Supplier :code added. Add the products it sells in its catalog.', ['code' => $supplier->supplier_code]));
        $this->modal('supplier-form')->close();
        $this->redirectRoute('purchasing.suppliers.show', ['supplier' => $supplier, 'tab' => 'catalog'], navigate: true);
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
        <flux:breadcrumbs.item :href="route('purchasing.dashboard')" wire:navigate>{{ __('Purchasing') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Suppliers') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">{{ __('Suppliers') }}</h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>
        @can('create', Supplier::class)
            <flux:button variant="primary" icon="plus" wire:click="openCreate" class="active:scale-[0.95]">{{ __('New Supplier') }}</flux:button>
        @endcan
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-4 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <div class="flex-1 min-w-56">
            <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search name, code or contact...')" />
        </div>
        <flux:switch wire:model.live="showInactive" :label="__('Show inactive')" align="left" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,showInactive,gotoPage,previousPage,nextPage" class="mt-4 transition-opacity motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <div class="overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Code') }}</th>
                        <th class="py-2 px-3">{{ __('Supplier') }}</th>
                        <th class="py-2 px-3">{{ __('Contact') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Products') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Purchase Orders') }}</th>
                        <th class="py-2 px-3">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->suppliers as $supplier)
                        <tr wire:key="sup-{{ $supplier->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors {{ $supplier->is_active ? '' : 'opacity-60' }}">
                            <td class="py-2 px-3 whitespace-nowrap">
                                <a href="{{ route('purchasing.suppliers.show', $supplier) }}" wire:navigate class="font-data text-accent hover:underline underline-offset-2">{{ $supplier->supplier_code }}</a>
                            </td>
                            <td class="py-2 px-3 text-zinc-800 dark:text-zinc-200">{{ $supplier->supplier_name }}</td>
                            <td class="py-2 px-3">
                                <p class="text-zinc-700 dark:text-zinc-300">{{ $supplier->contact_person ?? '—' }}</p>
                                <p class="text-zinc-500 dark:text-zinc-400">{{ collect([$supplier->phone, $supplier->email])->filter()->join(' · ') }}</p>
                            </td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $supplier->products_count }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $supplier->purchase_orders_count }}</td>
                            <td class="py-2 px-3">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $supplier->is_active ? 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20' : 'bg-zinc-100 text-zinc-500 border-zinc-200 dark:bg-white/5 dark:text-zinc-500 dark:border-white/10' }}">{{ $supplier->is_active ? __('Active') : __('Inactive') }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No suppliers match your filters.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $this->suppliers->links() }}</div>
    </div>

    <flux:modal name="supplier-form" focusable class="max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('New Supplier') }}</flux:heading>
                <flux:subheading>{{ __('A supplier code is generated automatically. You add its products and prices next.') }}</flux:subheading>
            </div>
            <flux:input wire:model="supplierName" :label="__('Supplier name')" />
            <div class="grid grid-cols-1 sm:grid-cols-2 items-start gap-4">
                <flux:input wire:model="contactPerson" :label="__('Contact person')" />
                <flux:input wire:model="phone" :label="__('Phone')" type="tel" />
            </div>
            <flux:input wire:model="email" :label="__('Email')" type="email" />
            <flux:textarea wire:model="address" :label="__('Address')" rows="2" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
