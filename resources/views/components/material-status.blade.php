@props(['status' => null, 'shortage' => null, 'unit' => ''])

@php
    $qty = $shortage !== null && str_contains((string) $shortage, '.') ? rtrim(rtrim((string) $shortage, '0'), '.') : (string) $shortage;
    $styles = [
        'enough' => 'border-green-200 bg-green-50 text-green-700 dark:border-green-500/20 dark:bg-green-500/10 dark:text-green-400',
        'incoming' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-400',
        'short' => 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-400',
    ];
@endphp

@if ($status)
    <span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap', $styles[$status]]) }}>
        @switch($status)
            @case('enough')
                <flux:icon.check-circle variant="micro" class="size-3.5" />
                {{ __('Sufficient') }}
                @break
            @case('incoming')
                <flux:icon.clock variant="micro" class="size-3.5" />
                {{ __('Short :qty', ['qty' => $qty]) }} {{ $unit }} · {{ __('on the way') }}
                @break
            @default
                <flux:icon.exclamation-triangle variant="micro" class="size-3.5" />
                {{ __('Short :qty', ['qty' => $qty]) }} {{ $unit }}
        @endswitch
    </span>
@else
    <span class="text-zinc-400 dark:text-zinc-500">—</span>
@endif
