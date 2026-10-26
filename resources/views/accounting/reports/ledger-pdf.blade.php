<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('General Ledger') }}</title>
    @include('production.reports._pdf-style')
</head>
<body>
    @php
        $rp = fn ($v) => (float) $v == 0 ? '' : number_format((float) $v, 0, ',', '.');
        $bal = fn ($v) => ((float) $v < 0 ? '(' : '').number_format(abs((float) $v), 0, ',', '.').((float) $v < 0 ? ')' : '');
    @endphp

    @include('production.reports._pdf-header', [
        'title' => __('GENERAL LEDGER'),
        'subtitle' => __('Period :period', ['period' => $periodLabel]),
    ])

    <table class="meta" style="margin-bottom: 8px;">
        <tr><td class="muted" style="width: 22%;">{{ __('Account') }}</td><td style="font-weight: bold;">{{ $account->account_code }} · {{ $account->account_name }}</td></tr>
        <tr><td class="muted">{{ __('Category') }}</td><td>{{ $account->type->label() }}@if ($account->is_contra) ({{ __('contra') }})@endif</td></tr>
        <tr><td class="muted">{{ __('Normal balance') }}</td><td>{{ $account->normal_balance->label() }}</td></tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th>{{ __('Date') }}</th>
                <th>{{ __('Journal') }}</th>
                <th>{{ __('Description') }}</th>
                <th class="right">{{ __('Debit') }}</th>
                <th class="right">{{ __('Credit') }}</th>
                <th class="right">{{ __('Running balance') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="5" class="muted">{{ __('Opening balance') }}</td>
                <td class="right" style="font-weight: bold;">{{ $bal($summary['opening']) }}</td>
            </tr>
            @forelse ($rows as $row)
                <tr>
                    <td style="white-space: nowrap;">{{ $row['date']->translatedFormat('d M Y') }}</td>
                    <td class="mono" style="white-space: nowrap;">{{ $row['journal_number'] }}</td>
                    <td>
                        {{ $row['description'] }}
                        @if ($row['tag'] === 'reversed') <span class="flag">[{{ __('Reversed') }}]</span>@elseif ($row['tag'] === 'reversal') <span class="flag">[{{ __('Reversal') }}]</span>@endif
                        @if ($row['counter'] !== '')<br><span class="muted">{{ __('vs :accounts', ['accounts' => $row['counter']]) }}</span>@endif
                    </td>
                    <td class="right">{{ $rp($row['debit']) }}</td>
                    <td class="right">{{ $rp($row['credit']) }}</td>
                    <td class="right">{{ $bal($row['balance']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="center muted" style="padding: 14px;">{{ __('No movements in this period.') }}</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="3">{{ __('Closing balance') }}</td>
                <td class="right">{{ number_format((float) $summary['debit'], 0, ',', '.') }}</td>
                <td class="right">{{ number_format((float) $summary['credit'], 0, ',', '.') }}</td>
                <td class="right">{{ $bal($summary['closing']) }}</td>
            </tr>
        </tbody>
    </table>
    <p class="muted" style="margin-top: 6px;">{{ __('Balance is shown on the account\'s normal side (:side); a figure in brackets is on the opposite side.', ['side' => $account->normal_balance->label()]) }}</p>
</body>
</html>
