<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('production.reports._pdf-style')
</head>
<body>
    @php
        $rp = fn ($v) => (float) $v == 0.0 ? '0' : (((float) $v < 0 ? '−' : '').number_format(abs((float) $v), 0, ',', '.'));
        $bucketLabel = fn (string $key) => match ($key) {
            'current' => __('Not yet due'),
            '1_30' => __('1-30 days'),
            '31_60' => __('31-60 days'),
            '61_90' => __('61-90 days'),
            default => __('Over 90 days'),
        };
    @endphp

    @include('production.reports._pdf-header', [
        'title' => $title,
        'subtitle' => __('As of :date', ['date' => $dateLabel]),
    ])

    <p class="muted" style="margin: 0 0 8px;">{{ __('Outstanding balance per :party, split by how overdue each invoice is. Amounts in Rupiah.', ['party' => mb_strtolower($partyLabel)]) }}</p>

    <table class="grid">
        <thead>
            <tr>
                <th>{{ $partyLabel }}</th>
                @foreach (\App\Support\Reports\AgingReport::agingKeys() as $key)
                    <th class="right">{{ $bucketLabel($key) }}</th>
                @endforeach
                <th class="right">{{ __('Total') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    @foreach (\App\Support\Reports\AgingReport::agingKeys() as $key)
                        <td class="right {{ $row['buckets'][$key] > 0 && $key !== 'current' ? 'bad' : '' }}">{{ $rp($row['buckets'][$key]) }}</td>
                    @endforeach
                    <td class="right" style="font-weight: bold;">{{ $rp($row['total']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="center muted" style="padding: 18px;">{{ __('Nothing outstanding.') }}</td></tr>
            @endforelse
            @if ($rows->isNotEmpty())
                <tr class="total">
                    <td>{{ __('Total') }}</td>
                    @foreach (\App\Support\Reports\AgingReport::agingKeys() as $key)
                        <td class="right">{{ $rp($rows->sum(fn ($row) => $row['buckets'][$key])) }}</td>
                    @endforeach
                    <td class="right">{{ $rp($summary['total']) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <p class="muted" style="margin-top: 8px;">{{ __(':count overdue, totalling :amount.', ['count' => $summary['overdue_count'], 'amount' => 'Rp '.$rp($summary['overdue'])]) }}</p>
</body>
</html>
