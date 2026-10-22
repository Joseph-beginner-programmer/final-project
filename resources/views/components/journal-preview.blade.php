@props(['lines', 'hideAmounts' => false])

@php($rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.'))
<div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-white/10">
    <table class="w-full text-xs border-collapse">
        <thead>
            <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 bg-zinc-50 dark:bg-white/5 border-b border-zinc-200 dark:border-white/10">
                <th class="py-2 px-3">{{ __('Account') }}</th>
                <th class="py-2 px-3 text-right">{{ __('Debit') }}</th>
                <th class="py-2 px-3 text-right">{{ __('Credit') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                @php($isDebit = $line['side'] === 'debit')
                <tr class="border-b border-zinc-100 dark:border-white/5 last:border-0">
                    <td class="py-2 px-3 {{ $isDebit ? '' : 'ps-9' }}">
                        @if ($line['pending'] ?? false)
                            <span class="italic text-zinc-400 dark:text-zinc-500">{{ $line['name'] }}</span>
                        @else
                            <span class="{{ $line['unmapped'] ? 'text-amber-700 dark:text-amber-400' : 'text-zinc-900 dark:text-white' }}">{{ $line['name'] }}</span>
                            @if ($line['code'])
                                <span class="ms-1 font-data text-[11px] text-zinc-400">{{ $line['code'] }}</span>
                            @endif
                            @if ($line['unmapped'])
                                <span class="ms-1 text-[10px] text-amber-700 dark:text-amber-400">({{ __('not mapped') }})</span>
                            @endif
                        @endif
                    </td>
                    @if ($hideAmounts)
                        <td class="py-2 px-3 text-right text-zinc-500 dark:text-zinc-400">@if ($isDebit)<flux:icon.check variant="micro" class="inline size-3.5" />@endif</td>
                        <td class="py-2 px-3 text-right text-zinc-500 dark:text-zinc-400">@if (! $isDebit)<flux:icon.check variant="micro" class="inline size-3.5" />@endif</td>
                    @else
                        <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-900 dark:text-white">{{ $isDebit ? $rupiah($line['debit']) : '' }}</td>
                        <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-900 dark:text-white">{{ $isDebit ? '' : $rupiah($line['credit']) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
        @if (! $hideAmounts)
        <tfoot>
            <tr class="border-t-2 border-zinc-200 dark:border-white/20 bg-zinc-50 dark:bg-white/5 font-semibold">
                <td class="py-2 px-3 text-zinc-900 dark:text-white">{{ __('Total') }}</td>
                <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-900 dark:text-white">{{ $rupiah(collect($lines)->sum(fn ($l) => (float) $l['debit'])) }}</td>
                <td class="py-2 px-3 text-right font-data tabular-nums whitespace-nowrap text-zinc-900 dark:text-white">{{ $rupiah(collect($lines)->sum(fn ($l) => (float) $l['credit'])) }}</td>
            </tr>
        </tfoot>
        @endif
    </table>
    @if ($hideAmounts)
        <p class="border-t border-zinc-100 dark:border-white/5 bg-zinc-50 dark:bg-white/5 px-3 py-1.5 text-[11px] text-zinc-500 dark:text-zinc-400">{{ __('Amounts are calculated automatically at FIFO cost and are visible to Accounting and Manager.') }}</p>
    @endif
</div>
