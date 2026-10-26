<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Income Statement') }}</title>
    @include('production.reports._pdf-style')
</head>
<body>
    @php
        $rp = fn ($v) => bccomp((string) $v, '0', 2) < 0 ? '('.number_format(abs((float) $v), 0, ',', '.').')' : number_format((float) $v, 0, ',', '.');
        $t = $data['totals'];
        $sections = [
            ['revenue', __('Revenue'), __('Total revenue')],
            ['cost_of_sales', __('Cost of goods sold'), __('Total cost of goods sold')],
            ['gross_profit'],
            ['expenses', __('Operating expenses'), __('Total operating expenses')],
            ['net_profit'],
        ];
    @endphp

    @include('production.reports._pdf-header', [
        'title' => __('INCOME STATEMENT'),
        'subtitle' => __('Period :period', ['period' => $monthLabel]),
    ])

    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('Account') }}</th>
                <th class="right" style="width: 22%;">{{ $monthLabel }}</th>
                <th class="right" style="width: 22%;">{{ $ytdLabel }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sections as $section)
                @if (count($section) === 1)
                    <tr class="total">
                        <td>{{ $section[0] === 'gross_profit' ? __('Gross profit') : (bccomp($t['net_profit']['month'], '0', 2) < 0 ? __('Net loss') : __('Net profit')) }}</td>
                        <td class="right">{{ $rp($t[$section[0]]['month']) }}</td>
                        <td class="right">{{ $rp($t[$section[0]]['ytd']) }}</td>
                    </tr>
                @else
                    <tr><td colspan="3" style="font-weight: bold; padding-top: 8px; border-bottom: none;">{{ $section[1] }}</td></tr>
                    @forelse ($data[$section[0]] as $line)
                        <tr>
                            <td style="padding-left: 14px;"><span class="mono muted">{{ $line['account']->account_code }}</span> {{ $line['account']->account_name }}@if ($line['account']->is_contra) <span class="muted">({{ __('contra') }})</span>@endif</td>
                            <td class="right">{{ $rp($line['month']) }}</td>
                            <td class="right">{{ $rp($line['ytd']) }}</td>
                        </tr>
                    @empty
                        <tr><td style="padding-left: 14px;" class="muted">{{ __('None this period') }}</td><td class="right">0</td><td class="right">0</td></tr>
                    @endforelse
                    <tr>
                        <td style="font-weight: bold;">{{ $section[2] }}</td>
                        <td class="right" style="font-weight: bold;">{{ $rp($t[$section[0]]['month']) }}</td>
                        <td class="right" style="font-weight: bold;">{{ $rp($t[$section[0]]['ytd']) }}</td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
    <p class="muted" style="margin-top: 8px;">{{ __('Cost of goods sold includes the production variances and overhead (actual minus applied). Figures in brackets are deductions. Amounts in Rupiah.') }}</p>
</body>
</html>
