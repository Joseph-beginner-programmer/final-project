@props(['workOrder'])

@php
    $product = $workOrder->product;
    $target = (string) $workOrder->quantity_target;
    $target = str_contains($target, '.') ? rtrim(rtrim($target, '0'), '.') : $target;
@endphp

<div {{ $attributes->class('rounded-xl bg-white dark:bg-zinc-900 shadow-lg shadow-zinc-900/10 dark:shadow-black/40 border-l-4 border-accent p-5 flex flex-col gap-4 sm:flex-row sm:items-center') }}>
    <div class="flex min-w-0 flex-1 items-center gap-4">
        <div class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
            <flux:icon.cube class="size-6" />
        </div>
        <div class="min-w-0">
            <p class="text-[11px] font-bold uppercase tracking-widest text-zinc-500 dark:text-zinc-400">{{ __('Product made') }}</p>
            <div class="mt-0.5 flex flex-wrap items-center gap-2">
                <p class="font-display text-xl sm:text-2xl font-bold leading-tight text-zinc-900 dark:text-white">{{ $product->product_name }}</p>
                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium {{ $product->type->badgeClasses() }}">{{ $product->type->label() }}</span>
            </div>
            <p class="mt-0.5 font-data text-xs text-accent">{{ $product->product_code }}</p>
        </div>
    </div>
    <dl class="grid grid-cols-3 gap-x-6 gap-y-1 border-t border-zinc-100 pt-3 text-xs sm:border-t-0 sm:border-l sm:pt-0 sm:pl-6 dark:border-white/10">
        <div>
            <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Work Order') }}</dt>
            <dd class="mt-0.5"><a href="{{ route('production.work-orders.show', $workOrder) }}" wire:navigate class="rounded font-data font-medium text-accent hover:underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ $workOrder->wo_number }}</a></dd>
        </div>
        <div>
            <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Work Center') }}</dt>
            <dd class="mt-0.5 font-medium text-zinc-800 dark:text-zinc-200">{{ __($workOrder->workCenter->name) }}</dd>
        </div>
        <div>
            <dt class="text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Target') }}</dt>
            <dd class="mt-0.5 whitespace-nowrap font-data font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $target }} <span class="font-normal text-zinc-500 dark:text-zinc-400">{{ $product->unit_of_measure }}</span></dd>
        </div>
    </dl>
</div>
