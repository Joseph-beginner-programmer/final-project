<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Purchase Price Variance Report') }}</title>
    @include('production.reports._pdf-style')
</head>
<body>
    @php
        $n = fn ($v) => number_format((float) $v, 2, ',', '.');
        $rp = fn ($v) => $v === null ? '—' : ((float) $v < 0 ? '−' : '').number_format(abs((float) $v), 0, ',', '.');
        $pct = fn (?float $v) => $v === null ? '—' : (($v > 0 ? '+' : '').number_format($v, 1, ',', '.').'%');
    @endphp

    @include('production.reports._pdf-header', [
        'title' => __('PURCHASE PRICE VARIANCE REPORT'),
        'subtitle' => __('Period :period', ['period' => $periodLabel]),
    ])

    <p class="muted" style="margin: 0 0 8px;">{{ __('Invoiced price vs. the lot value at receipt (Selisih Harga Pembelian), per invoice line. Amounts in Rupiah.') }}</p>

    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('Invoice') }}</th>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Supplier') }}</th>
                <th>{{ __('Product') }}</th>
                <th class="right">{{ __('Quantity') }}</th>
                <th class="right">{{ __('Invoice price') }}</th>
                <th class="right">{{ __('Lot unit cost') }}</th>
                <th class="right">{{ __('Charges') }}</th>
                <th class="right">{{ __('Variance') }}</th>
                <th class="right">{{ __('Rate') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="mono" style="white-space: nowrap;">{{ $r['invoice_number'] }}</td>
                    <td>{{ $r['invoice_date']->format('d/m/Y') }}</td>
                    <td>{{ $r['supplier'] }}</td>
                    <td>{{ $r['product'] }}</td>
                    <td class="right">{{ $n($r['quantity']) }}</td>
                    <td class="right">{{ $rp($r['unit_price']) }}</td>
                    <td class="right">{{ $rp($r['lot_unit_cost']) }}</td>
                    <td class="right">{{ $rp($r['charge_share']) }}</td>
                    <td class="right {{ (float) $r['variance'] > 0 ? 'bad' : ((float) $r['variance'] < 0 ? 'good' : '') }}" style="font-weight: bold;">{{ (float) $r['variance'] > 0 ? '+' : '' }}{{ $rp($r['variance']) }}</td>
                    <td class="right">{{ $pct($r['rate']) }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="center muted" style="padding: 18px;">{{ __('No invoice lines in this period.') }}</td></tr>
            @endforelse
            @if ($rows->isNotEmpty())
                <tr class="total">
                    <td colspan="5">{{ __('Total') }}</td>
                    <td class="right">{{ $rp($totals['amount']) }}</td>
                    <td class="right">{{ $rp($totals['received_value']) }}</td>
                    <td class="right">{{ $rp($totals['charge_share']) }}</td>
                    <td class="right">{{ (float) $totals['variance'] > 0 ? '+' : '' }}{{ $rp($totals['variance']) }}</td>
                    <td></td>
                </tr>
            @endif
        </tbody>
    </table>

    @if ($suppliers->isNotEmpty())
        <p style="margin: 16px 0 6px; font-weight: bold; font-size: 8.5pt;">{{ __('Summary per supplier') }}</p>
        <table class="grid">
            <thead>
                <tr>
                    <th>{{ __('Supplier') }}</th>
                    <th class="right">{{ __('Lines') }}</th>
                    <th class="right">{{ __('Invoiced amount') }}</th>
                    <th class="right">{{ __('Lot value') }}</th>
                    <th class="right">{{ __('Variance') }}</th>
                    <th class="right">{{ __('Rate') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($suppliers as $s)
                    <tr>
                        <td>{{ $s['supplier'] }}</td>
                        <td class="right">{{ $s['lines'] }}</td>
                        <td class="right">{{ $rp($s['amount']) }}</td>
                        <td class="right">{{ $rp($s['received_value']) }}</td>
                        <td class="right {{ (float) $s['variance'] > 0 ? 'bad' : ((float) $s['variance'] < 0 ? 'good' : '') }}" style="font-weight: bold;">{{ (float) $s['variance'] > 0 ? '+' : '' }}{{ $rp($s['variance']) }}</td>
                        <td class="right">{{ $pct($s['rate']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
