<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Purchase Order Report') }}</title>
    @include('production.reports._pdf-style')
</head>
<body>
    @php
        $n = fn ($v) => number_format((float) $v, 0, ',', '.');
        $rp = fn ($v) => $v === null ? '—' : ((float) $v < 0 ? '−' : '').number_format(abs((float) $v), 0, ',', '.');
        $pct = fn (?float $v) => $v === null ? '—' : number_format($v, 1, ',', '.').'%';
    @endphp

    @include('production.reports._pdf-header', [
        'title' => __('PURCHASE ORDER REPORT'),
        'subtitle' => __('Period :period', ['period' => $periodLabel]),
    ])

    <p class="muted" style="margin: 0 0 8px;">{{ __('Purchase orders created in this period. Amounts in Rupiah.') }}</p>

    @if ($statuses->isNotEmpty())
        <table class="grid" style="margin-bottom: 10px;">
            <thead>
                <tr>
                    <th>{{ __('Status') }}</th>
                    <th class="right">{{ __('Purchase orders') }}</th>
                    <th class="right">{{ __('Total amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($statuses as $s)
                    <tr>
                        <td>{{ $s['status']->label() }}</td>
                        <td class="right">{{ $n($s['count']) }}</td>
                        <td class="right">{{ $rp($s['total_amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('PO') }}</th>
                <th>{{ __('Supplier') }}</th>
                <th>{{ __('Order date') }}</th>
                <th>{{ __('Expected delivery') }}</th>
                <th>{{ __('Status') }}</th>
                <th class="right">{{ __('Items') }}</th>
                <th class="right">{{ __('Total amount') }}</th>
                <th class="right">{{ __('Received') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="mono" style="white-space: nowrap;">{{ $r['po_number'] }}{{ $r['overdue'] ? ' *' : '' }}</td>
                    <td>{{ $r['supplier'] }}</td>
                    <td>{{ $r['order_date']->format('d/m/Y') }}</td>
                    <td>{{ $r['expected_delivery_date']?->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $r['status']->label() }}</td>
                    <td class="right">{{ $n($r['items_count']) }}</td>
                    <td class="right" style="font-weight: bold;">{{ $rp($r['total_amount']) }}</td>
                    <td class="right">{{ $pct($r['received_rate']) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="center muted" style="padding: 18px;">{{ __('No purchase orders created in this period.') }}</td></tr>
            @endforelse
            @if ($rows->isNotEmpty())
                <tr class="total">
                    <td colspan="5">{{ __('Total') }}</td>
                    <td class="right">{{ $n($totals['items_count']) }}</td>
                    <td class="right">{{ $rp($totals['total_amount']) }}</td>
                    <td></td>
                </tr>
            @endif
        </tbody>
    </table>
    @if ($totals['overdue'] > 0)
        <p class="muted" style="margin-top: 6px;">{{ __('* overdue: past the expected delivery date and not yet fully received.') }} {{ __(':count of :total overdue.', ['count' => $totals['overdue'], 'total' => $totals['count']]) }}</p>
    @endif
</body>
</html>
