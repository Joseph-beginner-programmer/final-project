<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Trial Balance') }}</title>
    @include('production.reports._pdf-style')
</head>
<body>
    @php
        $rp = fn ($v) => number_format((float) $v, 0, ',', '.');
        $n = fn ($v) => number_format((float) $v, 0, ',', '.');
        $currentType = null;
    @endphp

    @include('production.reports._pdf-header', [
        'title' => __('TRIAL BALANCE'),
        'subtitle' => __('Period :period', ['period' => $periodLabel]),
    ])

    <p class="muted" style="margin: 0 0 8px;">{{ __('Opening balance at the start of the period, movements during the period, and closing balance per account. Amounts in Rupiah.') }}</p>

    <table class="grid">
        <thead>
            <tr>
                <th rowspan="2">{{ __('Code') }}</th>
                <th rowspan="2">{{ __('Account') }}</th>
                <th colspan="2" class="group">{{ __('Opening balance') }}</th>
                <th colspan="2" class="group">{{ __('Movements') }}</th>
                <th colspan="2" class="group">{{ __('Closing balance') }}</th>
            </tr>
            <tr>
                <th class="center">{{ __('Debit') }}</th><th class="center">{{ __('Credit') }}</th>
                <th class="center">{{ __('Debit') }}</th><th class="center">{{ __('Credit') }}</th>
                <th class="center">{{ __('Debit') }}</th><th class="center">{{ __('Credit') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @if ($currentType !== $row['account']->type)
                    @php($currentType = $row['account']->type)
                    <tr><td colspan="8" style="font-weight: bold; background: #fafafa;">{{ $currentType->label() }}</td></tr>
                @endif
                <tr>
                    <td class="mono">{{ $row['account']->account_code }}</td>
                    <td>{{ $row['account']->account_name }}@if ($row['account']->is_contra) <span class="muted">({{ __('contra') }})</span>@endif</td>
                    <td class="right">{{ $rp($row['opening_debit']) }}</td>
                    <td class="right">{{ $rp($row['opening_credit']) }}</td>
                    <td class="right">{{ $rp($row['debit']) }}</td>
                    <td class="right">{{ $rp($row['credit']) }}</td>
                    <td class="right" style="font-weight: bold;">{{ $rp($row['closing_debit']) }}</td>
                    <td class="right" style="font-weight: bold;">{{ $rp($row['closing_credit']) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="center muted" style="padding: 18px;">{{ __('No journal entries up to this period.') }}</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="2">{{ __('Total') }}</td>
                <td class="right">{{ $n($totals['opening_debit']) }}</td>
                <td class="right">{{ $n($totals['opening_credit']) }}</td>
                <td class="right">{{ $n($totals['debit']) }}</td>
                <td class="right">{{ $n($totals['credit']) }}</td>
                <td class="right">{{ $n($totals['closing_debit']) }}</td>
                <td class="right">{{ $n($totals['closing_credit']) }}</td>
            </tr>
        </tbody>
    </table>

    <p style="margin-top: 8px;" class="{{ $balanced ? 'good' : 'bad' }}">{{ $balanced ? __('Balanced: total debit equals total credit in every column.') : __('Not balanced: check the journal entries.') }}</p>
</body>
</html>
