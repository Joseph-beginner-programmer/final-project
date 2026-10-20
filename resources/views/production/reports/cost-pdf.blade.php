<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Production Cost Report') }}</title>
    @include('production.reports._pdf-style')
</head>
<body>
    @php
        $n = fn ($v) => number_format((float) $v, 0, ',', '.');
        $rp = fn ($v) => $v === null ? '—' : ((float) $v < 0 ? '−' : '').number_format(abs((float) $v), 0, ',', '.');
        $pct = fn ($part, $total) => (float) $total > 0 ? number_format((float) $part / (float) $total * 100, 1, ',', '.').'%' : '—';
    @endphp

    @include('production.reports._pdf-header', [
        'title' => __('PRODUCTION COST REPORT'),
        'subtitle' => __('Period :period · :type', ['period' => $periodLabel, 'type' => $typeLabel]),
    ])

    <p class="muted" style="margin: 0 0 8px;">{{ __('Work orders whose production result was posted in this period. Amounts in Rupiah. Unit cost = total cost ÷ good units.') }}</p>

    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('Work order') }}</th>
                <th>{{ __('Product') }}</th>
                <th>{{ __('Completed on') }}</th>
                <th class="right">{{ __('Good units') }}</th>
                <th class="right">{{ __('Material') }}</th>
                <th class="right">{{ __('Labor') }}</th>
                <th class="right">{{ __('Overhead') }}</th>
                <th class="right">{{ __('Total cost') }}</th>
                <th class="right">{{ __('Unit cost') }}</th>
                <th class="right">{{ __('Labor + overhead vs plan') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="mono" style="white-space: nowrap;">{{ $r['wo_number'] }}</td>
                    <td>{{ $r['product'] }}</td>
                    <td>{{ $r['completed_on']->translatedFormat('d M Y') }}</td>
                    <td class="right">{{ $n($r['good']) }}</td>
                    <td class="right">{{ $rp($r['material']) }}</td>
                    <td class="right">{{ $rp($r['labor']) }}</td>
                    <td class="right">{{ $rp($r['overhead']) }}</td>
                    <td class="right" style="font-weight: bold;">{{ $rp($r['total']) }}</td>
                    <td class="right" style="font-weight: bold;">{{ $rp($r['unit_cost']) }}</td>
                    <td class="right {{ (float) $r['difference'] > 0 ? 'bad' : '' }}">{{ (float) $r['difference'] > 0 ? '+' : '' }}{{ $rp($r['difference']) }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="center muted" style="padding: 18px;">{{ __('No work orders completed in this period.') }}</td></tr>
            @endforelse
            @if ($rows->isNotEmpty())
                <tr class="total">
                    <td colspan="3">{{ __('Total') }}</td>
                    <td class="right">{{ $n($totals['good']) }}</td>
                    <td class="right">{{ $rp($totals['material']) }}</td>
                    <td class="right">{{ $rp($totals['labor']) }}</td>
                    <td class="right">{{ $rp($totals['overhead']) }}</td>
                    <td class="right">{{ $rp($totals['total']) }}</td>
                    <td></td>
                    <td class="right">{{ (float) $totals['difference'] > 0 ? '+' : '' }}{{ $rp($totals['difference']) }}</td>
                </tr>
                <tr>
                    <td colspan="4" class="muted" style="border: none;">{{ __('Share of total') }}</td>
                    <td class="right muted" style="border: none;">{{ $pct($totals['material'], $totals['total']) }}</td>
                    <td class="right muted" style="border: none;">{{ $pct($totals['labor'], $totals['total']) }}</td>
                    <td class="right muted" style="border: none;">{{ $pct($totals['overhead'], $totals['total']) }}</td>
                    <td colspan="3" style="border: none;"></td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($products->isNotEmpty())
        <p style="margin: 16px 0 6px; font-weight: bold; font-size: 8.5pt;">{{ __('Summary per product') }}</p>
        <table class="grid">
            <thead>
                <tr>
                    <th>{{ __('Product') }}</th>
                    <th class="right">{{ __('Work orders') }}</th>
                    <th class="right">{{ __('Good units') }}</th>
                    <th class="right">{{ __('Material') }}</th>
                    <th class="right">{{ __('Labor') }}</th>
                    <th class="right">{{ __('Overhead') }}</th>
                    <th class="right">{{ __('Total cost') }}</th>
                    <th class="right">{{ __('Average unit cost') }}</th>
                    <th class="right">{{ __('Lowest – highest') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($products as $p)
                    <tr>
                        <td>{{ $p['product'] }}</td>
                        <td class="right">{{ $p['work_orders'] }}</td>
                        <td class="right">{{ $n($p['good']) }}</td>
                        <td class="right">{{ $rp($p['material']) }}</td>
                        <td class="right">{{ $rp($p['labor']) }}</td>
                        <td class="right">{{ $rp($p['overhead']) }}</td>
                        <td class="right" style="font-weight: bold;">{{ $rp($p['total']) }}</td>
                        <td class="right" style="font-weight: bold;">{{ $rp($p['average_unit_cost']) }}</td>
                        <td class="right muted">{{ $p['work_orders'] > 1 ? $rp($p['lowest_unit_cost']).' – '.$rp($p['highest_unit_cost']) : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
