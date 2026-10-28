<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Purchase Register') }}</title>
    @include('production.reports._pdf-style')
</head>
<body>
    @php
        $n = fn ($v) => number_format((float) $v, 0, ',', '.');
        $rp = fn ($v) => $v === null ? '—' : ((float) $v < 0 ? '−' : '').number_format(abs((float) $v), 0, ',', '.');
    @endphp

    @include('production.reports._pdf-header', [
        'title' => __('PURCHASE REGISTER'),
        'subtitle' => __('Period :period', ['period' => $periodLabel]),
    ])

    <p class="muted" style="margin: 0 0 8px;">{{ __('Purchase invoices posted in this period. Amounts in Rupiah.') }}</p>

    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('Invoice') }}</th>
                <th>{{ __('PO') }}</th>
                <th>{{ __('Supplier') }}</th>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Due') }}</th>
                <th class="right">{{ __('Goods') }}</th>
                <th class="right">{{ __('Charges') }}</th>
                <th class="right">{{ __('Tax') }}</th>
                <th class="right">{{ __('Total') }}</th>
                <th class="right">{{ __('Paid') }}</th>
                <th class="right">{{ __('Balance') }}</th>
                <th>{{ __('Status') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="mono" style="white-space: nowrap;">{{ $r['invoice']->internal_number }}</td>
                    <td class="mono" style="white-space: nowrap;">{{ $r['po_number'] }}</td>
                    <td>{{ $r['supplier'] }}</td>
                    <td>{{ $r['invoice_date']->format('d/m/Y') }}</td>
                    <td>{{ $r['due_date']->format('d/m/Y') }}</td>
                    <td class="right">{{ $rp($r['goods_amount']) }}</td>
                    <td class="right">{{ $rp($r['charges']) }}</td>
                    <td class="right">{{ $rp($r['tax_amount']) }}</td>
                    <td class="right" style="font-weight: bold;">{{ $rp($r['total_amount']) }}</td>
                    <td class="right">{{ $rp($r['paid']) }}</td>
                    <td class="right {{ (float) $r['balance'] > 0 ? 'flag' : '' }}">{{ $rp($r['balance']) }}</td>
                    <td>{{ $r['status']->label() }}</td>
                </tr>
            @empty
                <tr><td colspan="12" class="center muted" style="padding: 18px;">{{ __('No purchase invoices posted in this period.') }}</td></tr>
            @endforelse
            @if ($rows->isNotEmpty())
                <tr class="total">
                    <td colspan="5">{{ __('Total') }}</td>
                    <td class="right">{{ $rp($totals['goods_amount']) }}</td>
                    <td class="right">{{ $rp($totals['charges']) }}</td>
                    <td class="right">{{ $rp($totals['tax_amount']) }}</td>
                    <td class="right">{{ $rp($totals['total_amount']) }}</td>
                    <td class="right">{{ $rp($totals['paid']) }}</td>
                    <td class="right">{{ $rp($totals['balance']) }}</td>
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
                    <th class="right">{{ __('Invoices') }}</th>
                    <th class="right">{{ __('Goods') }}</th>
                    <th class="right">{{ __('Total') }}</th>
                    <th class="right">{{ __('Paid') }}</th>
                    <th class="right">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($suppliers as $s)
                    <tr>
                        <td>{{ $s['supplier'] }}</td>
                        <td class="right">{{ $n($s['invoices']) }}</td>
                        <td class="right">{{ $rp($s['goods_amount']) }}</td>
                        <td class="right" style="font-weight: bold;">{{ $rp($s['total_amount']) }}</td>
                        <td class="right">{{ $rp($s['paid']) }}</td>
                        <td class="right {{ (float) $s['balance'] > 0 ? 'flag' : '' }}">{{ $rp($s['balance']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
