<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Cash Flow Statement') }}</title>
    @include('production.reports._pdf-style')
</head>
<body>
    @php
        $rp = fn ($v) => bccomp((string) $v, '0', 2) < 0 ? '('.number_format(abs((float) $v), 0, ',', '.').')' : number_format((float) $v, 0, ',', '.');
        $t = $data['totals'];
        $row = fn ($line) => '<tr><td style="padding-left: 14px;"><span class="mono muted">'.e($line['account']->account_code).'</span> '.e($line['account']->account_name).'</td><td class="right">'.$rp($line['amount']).'</td></tr>';
        $section = fn ($heading, $lines, $total) => '<tr><td colspan="2" style="font-weight: bold; padding-top: 10px; border-bottom: none;">'.e($heading).'</td></tr>'
            .(count($lines) > 0 ? collect($lines)->map($row)->implode('') : '<tr><td class="muted" style="padding-left: 14px;">'.e(__('None')).'</td><td class="right">0</td></tr>')
            .'<tr><td style="font-weight: bold;">'.e(__('Net cash')).'</td><td class="right" style="font-weight: bold;">'.$rp($total).'</td></tr>';
    @endphp

    @include('production.reports._pdf-header', [
        'title' => __('CASH FLOW STATEMENT'),
        'subtitle' => $periodLabel,
    ])

    <table class="grid">
        <thead>
            <tr><th>{{ __('Account') }}</th><th class="right" style="width: 26%;">{{ __('Amount') }}</th></tr>
        </thead>
        <tbody>
            <tr><td style="font-weight: bold;">{{ __('Opening cash') }}</td><td class="right" style="font-weight: bold;">{{ $rp($data['opening_cash']) }}</td></tr>
            {!! $section(__('Operating activities'), $data['operating']->all(), $t['operating']) !!}
            {!! $section(__('Financing activities'), $data['financing']->all(), $t['financing']) !!}
            <tr class="total"><td>{{ __('Net change in cash') }}</td><td class="right">{{ $rp($data['net_change']) }}</td></tr>
            <tr><td style="font-weight: bold;">{{ __('Closing cash') }}</td><td class="right" style="font-weight: bold;">{{ $rp($data['closing_cash']) }}</td></tr>
        </tbody>
    </table>

    <p style="margin-top: 8px;" class="{{ $data['reconciled'] ? 'good' : 'bad' }}">{{ $data['reconciled'] ? __('Reconciled: opening cash plus net change equals closing cash.') : __('Not reconciled: check the journal entries.') }}</p>
    <p class="muted">{{ __('Cash accounts') }}: {{ $data['cash_accounts']->pluck('account_name')->implode(', ') }}. {{ __('Figures in brackets are cash outflows. Amounts in Rupiah.') }}</p>
</body>
</html>
