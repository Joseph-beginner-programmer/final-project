<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Balance Sheet') }}</title>
    @include('production.reports._pdf-style')
</head>
<body>
    @php
        $rp = fn ($v) => bccomp((string) $v, '0', 2) < 0 ? '('.number_format(abs((float) $v), 0, ',', '.').')' : number_format((float) $v, 0, ',', '.');
        $t = $data['totals'];
        $row = fn ($line) => '<tr><td style="padding-left: 14px;"><span class="mono muted">'.e($line['account']->account_code).'</span> '.e($line['account']->account_name).($line['account']->is_contra ? ' <span class="muted">('.e(__('contra')).')</span>' : '').'</td><td class="right">'.$rp($line['amount']).'</td></tr>';
    @endphp

    @include('production.reports._pdf-header', [
        'title' => __('BALANCE SHEET'),
        'subtitle' => __('As of :date', ['date' => $asOfLabel]),
    ])

    <table class="grid">
        <thead>
            <tr><th>{{ __('Account') }}</th><th class="right" style="width: 26%;">{{ __('Amount') }}</th></tr>
        </thead>
        <tbody>
            <tr><td colspan="2" style="font-weight: bold; padding-top: 8px; border-bottom: none;">{{ __('Assets') }}</td></tr>
            @foreach ($data['assets'] as $line) {!! $row($line) !!} @endforeach
            <tr class="total"><td>{{ __('Total assets') }}</td><td class="right">{{ $rp($t['assets']) }}</td></tr>

            <tr><td colspan="2" style="font-weight: bold; padding-top: 14px; border-bottom: none;">{{ __('Liabilities') }}</td></tr>
            @forelse ($data['liabilities'] as $line) {!! $row($line) !!} @empty <tr><td class="muted" style="padding-left: 14px;">{{ __('None') }}</td><td class="right">0</td></tr> @endforelse
            <tr><td style="font-weight: bold;">{{ __('Total liabilities') }}</td><td class="right" style="font-weight: bold;">{{ $rp($t['liabilities']) }}</td></tr>

            <tr><td colspan="2" style="font-weight: bold; padding-top: 8px; border-bottom: none;">{{ __('Equity') }}</td></tr>
            @foreach ($data['equity'] as $line) {!! $row($line) !!} @endforeach
            <tr><td style="padding-left: 14px;">{{ __('Current earnings (not yet closed)') }}</td><td class="right">{{ $rp($data['profit']) }}</td></tr>
            <tr><td style="font-weight: bold;">{{ __('Total equity') }}</td><td class="right" style="font-weight: bold;">{{ $rp($t['equity']) }}</td></tr>
            <tr class="total"><td>{{ __('Total liabilities and equity') }}</td><td class="right">{{ $rp($t['liabilities_and_equity']) }}</td></tr>
        </tbody>
    </table>

    <p style="margin-top: 8px;" class="{{ $data['balanced'] ? 'good' : 'bad' }}">{{ $data['balanced'] ? __('Balanced: total assets equal total liabilities and equity.') : __('Not balanced: check the journal entries.') }}</p>
    <p class="muted">{{ __('Current earnings = all revenue minus all expenses so far; there is no year-end closing yet.') }} {{ __('Figures in brackets are deductions. Amounts in Rupiah.') }}</p>
</body>
</html>
