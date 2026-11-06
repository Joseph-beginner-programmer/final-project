<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Gross Margin Report') }}</title>
    @include('production.reports._pdf-style')
</head>
<body>
    @php
        $n = fn ($v) => number_format((float) $v, 0, ',', '.');
        $rp = fn ($v) => ((float) $v < 0 ? '−' : '').number_format(abs((float) $v), 0, ',', '.');
        $pct = fn (?float $v) => $v === null ? '—' : number_format($v, 1, ',', '.').'%';
        $revenue = (float) $rows->sum('revenue');
        $cost = (float) $rows->sum('cost');
        $profit = $revenue - $cost;
        $margin = $revenue > 0 ? $profit / $revenue * 100 : null;
    @endphp

    @include('production.reports._pdf-header', [
        'title' => __('GROSS MARGIN REPORT'),
        'subtitle' => __('Period :period', ['period' => $periodLabel]),
    ])

    <p class="muted" style="margin: 0 0 8px;">{{ __('Revenue vs. cost of goods sold on posted deliveries, :scope. Amounts in Rupiah.', ['scope' => $byCustomer ? __('by customer') : __('by product')]) }}</p>

    <table class="grid">
        <thead>
            <tr>
                <th>{{ $byCustomer ? __('Customer') : __('Product') }}</th>
                @unless ($byCustomer)
                    <th class="right">{{ __('Quantity') }}</th>
                @endunless
                <th class="right">{{ __('Revenue') }}</th>
                <th class="right">{{ __('Cost of goods sold') }}</th>
                <th class="right">{{ __('Gross profit') }}</th>
                <th class="right">{{ __('Margin') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    @unless ($byCustomer)
                        <td class="right">{{ $n($row['quantity']) }}</td>
                    @endunless
                    <td class="right">{{ $rp($row['revenue']) }}</td>
                    <td class="right">{{ $rp($row['cost']) }}</td>
                    <td class="right {{ $row['profit'] < 0 ? 'bad' : '' }}" style="font-weight: bold;">{{ $rp($row['profit']) }}</td>
                    <td class="right">{{ $pct($row['margin']) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $byCustomer ? 5 : 6 }}" class="center muted" style="padding: 18px;">{{ __('No deliveries in this period.') }}</td></tr>
            @endforelse
            @if ($rows->isNotEmpty())
                <tr class="total">
                    <td colspan="{{ $byCustomer ? 1 : 2 }}">{{ __('Total') }}</td>
                    <td class="right">{{ $rp($revenue) }}</td>
                    <td class="right">{{ $rp($cost) }}</td>
                    <td class="right">{{ $rp($profit) }}</td>
                    <td class="right">{{ $pct($margin) }}</td>
                </tr>
            @endif
        </tbody>
    </table>
</body>
</html>
