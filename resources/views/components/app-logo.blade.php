@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="PT SAI" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg border bg-zinc-900 text-accent" style="border-color: color-mix(in srgb, var(--color-accent) 50%, transparent);">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="PT SAI" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg border bg-zinc-900 text-accent" style="border-color: color-mix(in srgb, var(--color-accent) 50%, transparent);">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:brand>
@endif
