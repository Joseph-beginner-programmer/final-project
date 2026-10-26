@php($maxBucket = max(1, collect($aging['buckets'])->max('amount')))

@if ($aging['total'] <= 0)
    <p class="py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ $emptyText }}</p>
@else
    <div class="mt-4 grid grid-cols-1 {{ $partyTitle ? 'md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] md:gap-8' : '' }} gap-5">
        <div>
            <div class="flex items-baseline justify-between gap-3 text-xs">
                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Total open') }} <span class="font-data font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($aging['total']) }}</span></span>
                @if ($aging['overdue'] > 0)
                    <span class="font-medium text-red-700 dark:text-red-400">{{ __(':amount overdue', ['amount' => $this->formatCompact($aging['overdue'])]) }}</span>
                @endif
            </div>
            <div class="mt-3 space-y-2.5">
                @foreach ($aging['buckets'] as $bucket)
                    <div class="grid grid-cols-[6.5rem_minmax(0,1fr)] sm:grid-cols-[7.5rem_minmax(0,1fr)_7rem] items-center gap-x-3 gap-y-1 text-xs">
                        <span class="text-zinc-600 dark:text-zinc-300">{{ $labels[$bucket['key']] }}</span>
                        <div class="h-3 rounded" style="background: var(--viz-grid)">
                            @if ($bucket['amount'] > 0)
                                <div class="h-full rounded" style="width: {{ max(1.5, $bucket['amount'] / $maxBucket * 100) }}%; background: {{ $colors[$bucket['key']] }}"></div>
                            @endif
                        </div>
                        <span class="col-start-2 sm:col-start-auto text-right font-data tabular-nums {{ $bucket['amount'] > 0 ? 'text-zinc-900 dark:text-white' : 'text-zinc-400 dark:text-zinc-500' }}">
                            {{ $this->formatCompact($bucket['amount']) }}<span class="ms-1 font-sans text-[11px] text-zinc-400 dark:text-zinc-500">({{ $bucket['count'] }})</span>
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        @if ($partyTitle)
            <div class="md:border-s md:border-zinc-100 md:dark:border-white/5 md:ps-6">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $partyTitle }}</p>
                <ul class="mt-2 divide-y divide-zinc-100 dark:divide-white/5">
                    @forelse ($aging['top'] as $party)
                        <li class="flex items-center justify-between gap-3 py-2 text-xs">
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-zinc-800 dark:text-zinc-200">{{ $party['name'] }}</span>
                                <span class="text-[11px] text-red-700 dark:text-red-400">{{ trans_choice(':count day late|:count days late', $party['days'], ['count' => $party['days']]) }}</span>
                            </span>
                            <span class="shrink-0 font-data font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $this->formatRupiah($party['amount']) }}</span>
                        </li>
                    @empty
                        <li class="flex items-center gap-2 py-2 text-xs text-zinc-500 dark:text-zinc-400"><flux:icon.check-circle variant="micro" class="size-4 text-green-600 dark:text-green-400" />{{ __('Nobody is late.') }}</li>
                    @endforelse
                </ul>
            </div>
        @endif
    </div>
@endif
