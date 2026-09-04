<x-layouts.app>
    <div class="p-6">
        <h1 class="text-xl font-bold">{{ auth()->user()->role->label() }} {{ __('Dashboard') }}</h1>
        <p>{{ __('Coming soon.') }}</p>
    </div>
</x-layouts.app>