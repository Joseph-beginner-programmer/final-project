@php
    use App\Enums\UserRole;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-zinc-100 dark:bg-zinc-950">
    <flux:sidebar sticky collapsible
        class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.header>
            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
            <flux:sidebar.collapse />
        </flux:sidebar.header>

        <flux:sidebar.nav>
            @foreach (UserRole::cases() as $role)
                @can('view-dashboard', $role)
                    <flux:sidebar.group :heading="$role->label()" expandable class="grid">
                        <flux:sidebar.item icon="home" :href="route($role->dashboardRoute())"
                            :current="request()->routeIs($role->dashboardRoute())" wire:navigate>
                            {{ __('Dashboard') }}
                        </flux:sidebar.item>

                        @if ($role === UserRole::Purchasing)
                            <flux:sidebar.item icon="queue-list" :href="route('purchasing.orders.list')"
                                :current="request()->routeIs('purchasing.orders.list', 'purchasing.orders.create')" wire:navigate>
                                {{ __('Purchase Order') }}
                            </flux:sidebar.item>
                        @endif

                        @if ($role === UserRole::Accounting)
                            <flux:sidebar.item icon="banknotes" :href="route('accounting.labor-rates.list')"
                                :current="request()->routeIs('accounting.labor-rates.*')" wire:navigate>
                                {{ __('Labor Rates') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="bolt" :href="route('accounting.overhead-rates.list')"
                                :current="request()->routeIs('accounting.overhead-rates.*')" wire:navigate>
                                {{ __('Overhead Rates') }}
                            </flux:sidebar.item>
                        @endif

                        @if ($role === UserRole::Manager)
                            <flux:sidebar.item icon="users" :href="route('management.employees.list')"
                                :current="request()->routeIs('management.employees.*')" wire:navigate>
                                {{ __('Employees') }}
                            </flux:sidebar.item>
                        @endif

                        @if ($role === UserRole::Production)
                            <flux:sidebar.item icon="wrench-screwdriver" :href="route('production.work-orders.list')"
                                :current="request()->routeIs('production.work-orders.*')" wire:navigate>
                                {{ __('Work Order') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="clipboard-document-check" :href="route('production.results.list')"
                                :current="request()->routeIs('production.results.*')" wire:navigate>
                                {{ __('Production Results') }}
                            </flux:sidebar.item>
                        @endif

                        @if ($role === UserRole::Warehouse)
                            <flux:sidebar.item icon="inbox-arrow-down" :href="route('warehouse.inbound.item-receipts.create')"
                            :current="request()->routeIs('warehouse.inbound.item-receipts.list', 'warehouse.inbound.item-receipts.create')" wire:navigate>
                                {{ __('Item Receipt') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="arrow-up-tray" :href="route('warehouse.outbound.material-issues.list')"
                                :current="request()->routeIs('warehouse.outbound.material-issues.*')" wire:navigate>
                                {{ __('Material Issue') }}
                            </flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endcan
            @endforeach
        </flux:sidebar.nav>

        <flux:spacer />

        <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
    </flux:sidebar>

    <!-- Mobile User Menu -->
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown position="top" align="end">
            <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />

            <flux:menu>
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                            <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                        {{ __('Settings') }}
                    </flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                        class="w-full cursor-pointer" data-test="logout-button">
                        {{ __('Log out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    {{ $slot }}

    @persist('toast')
    <flux:toast.group>
        <flux:toast />
    </flux:toast.group>
    @endpersist

    @fluxScripts
</body>

</html>