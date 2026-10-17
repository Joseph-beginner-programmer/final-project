@props(['rows' => [], 'unit' => '', 'empty' => ''])

@php
    $routeFor = fn ($document) => match (true) {
        $document instanceof \App\Models\SalesOrder => route('sales.orders.show', $document),
        $document instanceof \App\Models\PurchaseOrder => route('purchasing.orders.show', $document),
        $document instanceof \App\Models\WorkOrder => route('production.work-orders.show', $document),
        default => null,
    };
    $icons = ['sales' => 'document-text', 'purchase' => 'shopping-cart', 'production' => 'wrench-screwdriver'];
    $format = fn (string $q) => str_contains($q, '.') ? rtrim(rtrim($q, '0'), '.') : $q;
@endphp

@if (count($rows) === 0)
    <p class="rounded-md border border-dashed border-zinc-200 dark:border-white/10 px-3 py-4 text-center text-xs text-zinc-500 dark:text-zinc-400">{{ $empty }}</p>
@else
    <ul {{ $attributes->class('rounded-md border border-zinc-200 dark:border-white/10 divide-y divide-zinc-100 dark:divide-white/5') }}>
        @foreach ($rows as $row)
            @php
                $late = ! $row['ready'] && $row['date'] && $row['date']->isPast() && ! $row['date']->isToday();
                $url = isset($row['document']) && auth()->user()?->can('view', $row['document']) ? $routeFor($row['document']) : null;
            @endphp
            <li class="flex items-start justify-between gap-3 px-3 py-2.5 text-xs">
                <div class="flex min-w-0 items-start gap-2.5">
                    <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400">
                        <flux:icon :name="$icons[$row['source']] ?? 'document'" variant="micro" class="size-3.5" />
                    </span>
                    <div class="min-w-0">
                        @if ($url)
                            <a href="{{ $url }}" target="_blank" rel="noopener"
                                class="inline-flex items-center gap-1 rounded font-data font-medium text-accent hover:underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                aria-label="{{ __('Open :number in a new tab', ['number' => $row['number']]) }}">
                                {{ $row['number'] }}
                                <flux:icon.arrow-top-right-on-square variant="micro" class="size-3" />
                            </a>
                        @else
                            <p class="font-data font-medium text-zinc-800 dark:text-zinc-200">{{ $row['number'] }}</p>
                        @endif
                        <p class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ $row['party'] }} · {{ $row['note'] }}</p>
                    </div>
                </div>
                <div class="shrink-0 text-right">
                    <p class="font-data font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $format((string) $row['quantity']) }} {{ $unit }}</p>
                    <p class="font-data tabular-nums {{ $late ? 'text-red-600 dark:text-red-400' : 'text-zinc-500 dark:text-zinc-400' }}">
                        @if ($row['source'] === 'sales')
                            {{ __('deliver by') }} {{ $row['date']?->format('d M Y') ?? __('no date') }}@if ($late) · {{ __('late') }}@endif
                        @else
                            {{ $row['ready'] ? __('finished') : __('expected') }} {{ $row['date']?->format('d M Y') ?? __('no date') }}@if ($late) · {{ __('late') }}@endif
                        @endif
                    </p>
                </div>
            </li>
        @endforeach
    </ul>
@endif
