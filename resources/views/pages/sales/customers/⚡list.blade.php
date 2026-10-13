<?php

use App\Actions\Sales\SaveCustomerAction;
use App\Models\Customer;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Customers')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Locked]
    public ?int $editingId = null;

    public string $customerName = '';

    public string $contactPerson = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Customer::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function customers(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $term = str_replace(['%', '_'], ['\%', '\_'], $this->search);

        return Customer::query()
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('customer_name', 'like', "%{$term}%")
                ->orWhere('customer_code', 'like', "%{$term}%")
                ->orWhere('contact_person', 'like', "%{$term}%")))
            ->withCount('salesOrders')
            ->orderBy('customer_name')
            ->paginate(15);
    }

    public function openCreate(): void
    {
        Gate::authorize('create', Customer::class);

        $this->resetForm();
        $this->modal('customer-form')->show();
    }

    public function openEdit(int $id): void
    {
        $customer = Customer::findOrFail($id);
        Gate::authorize('update', $customer);

        $this->resetValidation();
        $this->editingId = $customer->id;
        $this->customerName = $customer->customer_name;
        $this->contactPerson = (string) $customer->contact_person;
        $this->phone = (string) $customer->phone;
        $this->email = (string) $customer->email;
        $this->address = (string) $customer->address;
        $this->modal('customer-form')->show();
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'customerName', 'contactPerson', 'phone', 'email', 'address');
    }

    protected function rules(): array
    {
        return [
            'customerName' => ['required', 'string', 'max:255'],
            'contactPerson' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'customerName' => __('Customer name'),
            'contactPerson' => __('Contact person'),
            'phone' => __('Phone'),
            'email' => __('Email'),
            'address' => __('Address'),
        ];
    }

    public function save(): void
    {
        $this->validate();

        $fields = [
            'customer_name' => $this->customerName,
            'contact_person' => $this->contactPerson,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
        ];

        if ($this->editingId) {
            $customer = Customer::findOrFail($this->editingId);
            Gate::authorize('update', $customer);
            app(SaveCustomerAction::class)->handle($fields, $customer);
            Flux::toast(variant: 'success', text: __('Customer updated.'));
        } else {
            Gate::authorize('create', Customer::class);
            $customer = app(SaveCustomerAction::class)->handle($fields);
            Flux::toast(variant: 'success', text: __('Customer :code added.', ['code' => $customer->customer_code]));
        }

        $this->modal('customer-form')->close();
        $this->resetForm();
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
        <flux:breadcrumbs.item :href="route('sales.dashboard')" wire:navigate>{{ __('Sales') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Customers') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="mt-3 flex flex-wrap items-start justify-between gap-4 motion-safe:animate-fade-slide-up">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold tracking-tight text-zinc-900 dark:text-white leading-tight">
                {{ __('Customers') }}
            </h1>
            <div class="w-10 h-0.5 mt-2 rounded-full bg-accent"></div>
        </div>

        @can('create', Customer::class)
            <flux:button variant="primary" icon="user-plus" wire:click="openCreate" class="active:scale-[0.95]">
                {{ __('New Customer') }}
            </flux:button>
        @endcan
    </div>

    <div class="mt-6 motion-safe:animate-fade-slide-up" style="animation-delay: 40ms;">
        <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.400ms="search" :placeholder="__('Search name, code or contact...')" />
    </div>

    <div wire:loading.class="opacity-50" wire:target="search,gotoPage,previousPage,nextPage" class="transition-opacity duration-150 motion-safe:animate-fade-slide-up" style="animation-delay: 70ms;">
        <div class="mt-4 hidden md:block overflow-x-auto rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40">
            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                        <th class="py-2 px-3">{{ __('Code') }}</th>
                        <th class="py-2 px-3">{{ __('Customer') }}</th>
                        <th class="py-2 px-3">{{ __('Contact') }}</th>
                        <th class="py-2 px-3">{{ __('Address') }}</th>
                        <th class="py-2 px-3 text-right">{{ __('Orders') }}</th>
                        <th class="py-2 px-3 w-12"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->customers as $customer)
                        <tr wire:key="cus-{{ $customer->id }}" class="border-b border-zinc-100 dark:border-white/5 last:border-0 hover:bg-zinc-50/70 dark:hover:bg-white/[0.03] transition-colors">
                            <td class="py-2 px-3 font-data text-accent whitespace-nowrap">{{ $customer->customer_code }}</td>
                            <td class="py-2 px-3 text-zinc-800 dark:text-zinc-200">{{ $customer->customer_name }}</td>
                            <td class="py-2 px-3">
                                <p class="text-zinc-700 dark:text-zinc-300">{{ $customer->contact_person ?? '—' }}</p>
                                <p class="text-zinc-500 dark:text-zinc-400">{{ collect([$customer->phone, $customer->email])->filter()->join(' · ') }}</p>
                            </td>
                            <td class="py-2 px-3 text-zinc-600 dark:text-zinc-400 max-w-xs truncate" title="{{ $customer->address }}">{{ $customer->address ?? '—' }}</td>
                            <td class="py-2 px-3 text-right font-data tabular-nums text-zinc-700 dark:text-zinc-300">{{ $customer->sales_orders_count }}</td>
                            <td class="py-2 px-3 text-right">
                                @can('update', $customer)
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="openEdit({{ $customer->id }})" :aria-label="__('Edit :name', ['name' => $customer->customer_name])" class="active:scale-[0.95]" />
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No customers yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 md:hidden rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 divide-y divide-zinc-100 dark:divide-white/5 overflow-hidden">
            @forelse ($this->customers as $customer)
                <button type="button" wire:key="cus-m-{{ $customer->id }}" wire:click="openEdit({{ $customer->id }})"
                    class="w-full text-left block px-4 py-3 hover:bg-zinc-50 dark:hover:bg-white/3 active:scale-[0.98] transition-[background-color,transform] cursor-pointer">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm text-zinc-800 dark:text-zinc-200">{{ $customer->customer_name }}</span>
                        <span class="font-data text-xs text-accent">{{ $customer->customer_code }}</span>
                    </div>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ collect([$customer->contact_person, $customer->phone])->filter()->join(' · ') ?: '—' }}</p>
                </button>
            @empty
                <p class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ __('No customers yet.') }}</p>
            @endforelse
        </div>
    </div>

    <div class="mt-4">
        {{ $this->customers->links() }}
    </div>

    <flux:modal name="customer-form" focusable class="max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit Customer') : __('New Customer') }}</flux:heading>
                <flux:subheading>{{ $editingId ? __('Changes apply to new and existing orders.') : __('A customer code is generated automatically.') }}</flux:subheading>
            </div>

            <flux:input wire:model="customerName" :label="__('Customer name')" />
            <div class="grid grid-cols-1 sm:grid-cols-2 items-start gap-4">
                <flux:input wire:model="contactPerson" :label="__('Contact person')" />
                <flux:input wire:model="phone" :label="__('Phone')" type="tel" />
            </div>
            <flux:input wire:model="email" :label="__('Email')" type="email" />
            <flux:textarea wire:model="address" :label="__('Address')" rows="2" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" class="active:scale-[0.95]">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save" class="active:scale-[0.95]">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
