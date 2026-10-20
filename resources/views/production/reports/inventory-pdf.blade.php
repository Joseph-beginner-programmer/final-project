<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Warehouse Inventory Report') }}</title>
    @include('production.reports._pdf-style')
</head>
<body>
    @php
        $q = function ($v) {
            $formatted = number_format((float) $v, 2, ',', '.');

            return str_ends_with($formatted, ',00') ? substr($formatted, 0, -3) : $formatted;
        };
        $rp = fn ($v) => ((float) $v < 0 ? '−' : '').number_format(abs((float) $v), 0, ',', '.');
    @endphp

    @include('production.reports._pdf-header', [
        'title' => __('WAREHOUSE INVENTORY REPORT'),
        'subtitle' => __('Period :period · :type', ['period' => $periodLabel, 'type' => $typeLabel]),
    ])

    <p class="muted" style="margin: 0 0 8px;">{{ __('Closing = opening + in − out, from the stock movement records.') }}@if ($withValue) {{ __('Value at FIFO cost, in Rupiah.') }}@endif</p>

    <table class="grid">
        <thead>
            @if ($withValue)
                <tr>
                    <th colspan="4" style="background: none; border: none;"></th>
                    <th colspan="4" class="group">{{ __('Quantity') }}</th>
                    <th colspan="4" class="group">{{ __('Value (FIFO)') }}</th>
                </tr>
            @endif
            <tr>
                <th>{{ __('Code') }}</th>
                <th>{{ __('Item') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Unit') }}</th>
                <th class="right">{{ __('Opening') }}</th>
                <th class="right">{{ __('In') }}</th>
                <th class="right">{{ __('Out') }}</th>
                <th class="right">{{ __('Closing') }}</th>
                @if ($withValue)
                    <th class="right">{{ __('Opening') }}</th>
                    <th class="right">{{ __('In') }}</th>
                    <th class="right">{{ __('Out') }}</th>
                    <th class="right">{{ __('Closing') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td class="mono">{{ $r['product']->product_code }}</td>
                    <td>{{ $r['product']->product_name }}</td>
                    <td>{{ $r['product']->type->label() }}</td>
                    <td>{{ $r['product']->unit_of_measure }}</td>
                    <td class="right">{{ $q($r['opening_qty']) }}</td>
                    <td class="right">{{ $q($r['in_qty']) }}</td>
                    <td class="right">{{ $q($r['out_qty']) }}</td>
                    <td class="right" style="font-weight: bold;">{{ $q($r['closing_qty']) }}</td>
                    @if ($withValue)
                        <td class="right">{{ $rp($r['opening_value']) }}</td>
                        <td class="right">{{ $rp($r['in_value']) }}</td>
                        <td class="right">{{ $rp($r['out_value']) }}</td>
                        <td class="right" style="font-weight: bold;">{{ $rp($r['closing_value']) }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $withValue ? 12 : 8 }}" class="center muted" style="padding: 18px;">{{ __('No items for this filter.') }}</td></tr>
            @endforelse
            @if ($withValue && $rows->isNotEmpty())
                <tr class="total">
                    <td colspan="8">{{ __('Total value') }}</td>
                    <td class="right">{{ $rp($totals['opening_value']) }}</td>
                    <td class="right">{{ $rp($totals['in_value']) }}</td>
                    <td class="right">{{ $rp($totals['out_value']) }}</td>
                    <td class="right">{{ $rp($totals['closing_value']) }}</td>
                </tr>
            @endif
        </tbody>
    </table>
</body>
</html>
