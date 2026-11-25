<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $delivery->delivery_number }}</title>
    <style>
        @page { margin: 22mm 16mm 20mm 16mm; }
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 9pt; color: #18181b; line-height: 1.4; }
        table { border-collapse: collapse; width: 100%; }
        .muted { color: #71717a; }
        .label { font-size: 7pt; font-weight: bold; letter-spacing: .06em; text-transform: uppercase; color: #71717a; }
        .right { text-align: right; }
        .center { text-align: center; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; }
        .header td { vertical-align: top; }
        .company { font-size: 13pt; font-weight: bold; }
        .title { font-size: 15pt; font-weight: bold; letter-spacing: .02em; }
        .rule { border-bottom: 2px solid #0e7490; margin: 10px 0 14px; }
        .box { border: 1px solid #e4e4e7; padding: 8px 10px; vertical-align: top; }
        .items th { background: #f4f4f5; font-size: 7pt; font-weight: bold; text-transform: uppercase; letter-spacing: .05em; color: #52525b; padding: 6px 6px; border-bottom: 1px solid #d4d4d8; }
        .items td { padding: 6px 6px; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
        .sign td { width: 33%; vertical-align: top; padding-top: 6px; }
        .sign-line { border-top: 1px solid #a1a1aa; margin-top: 52px; padding-top: 4px; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7pt; color: #a1a1aa; }
    </style>
</head>
<body>
    @php
        $qty = fn ($v) => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v;
        $date = fn ($d) => $d?->locale(app()->getLocale())->translatedFormat('d F Y') ?? '—';
        $order = $delivery->salesOrder;
        $customer = $order->customer;
    @endphp

    <table class="header">
        <tr>
            <td style="width: 55%;">
                @if ($logo)
                    <img src="{{ $logo }}" style="max-height: 40px; max-width: 160px; margin-bottom: 4px;" alt="">
                    <br>
                @endif
                <span class="company">{{ $company }}</span>
            </td>
            <td class="right">
                <div class="title">{{ __('DELIVERY NOTE') }}</div>
                <div class="mono" style="font-size: 11pt; color: #0e7490; margin-top: 2px;">{{ $delivery->delivery_number }}</div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table>
        <tr>
            <td class="box" style="width: 55%;">
                <div class="label">{{ __('To') }}</div>
                <div style="font-size: 10.5pt; font-weight: bold; margin-top: 3px;">{{ $customer->customer_name }}</div>
                @if ($customer->contact_person)
                    <div>{{ __('Attn.') }} {{ $customer->contact_person }}</div>
                @endif
                @if ($customer->address)
                    <div class="muted" style="margin-top: 2px;">{!! nl2br(e($customer->address)) !!}</div>
                @endif
                @if ($customer->phone || $customer->email)
                    <div class="muted" style="margin-top: 2px;">{{ collect([$customer->phone, $customer->email])->filter()->implode(' · ') }}</div>
                @endif
            </td>
            <td style="width: 3%;"></td>
            <td class="box">
                <table>
                    <tr><td class="label" style="padding-bottom: 6px;">{{ __('Delivery Date') }}</td><td class="right" style="padding-bottom: 6px;">{{ $date($delivery->delivered_at) }}</td></tr>
                    <tr><td class="label" style="padding-bottom: 6px;">{{ __('Sales Order') }}</td><td class="right mono" style="padding-bottom: 6px;">{{ $order->so_number }}</td></tr>
                    <tr><td class="label">{{ __('Customer Code') }}</td><td class="right mono">{{ $customer->customer_code }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <p style="margin: 14px 0 8px;">{{ __('The following goods have been delivered:') }}</p>

    <table class="items">
        <thead>
            <tr>
                <th class="center" style="width: 6%;">{{ __('No') }}</th>
                <th style="text-align: left; width: 16%;">{{ __('Code') }}</th>
                <th style="text-align: left;">{{ __('Item') }}</th>
                <th class="right" style="width: 18%;">{{ __('Qty') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($delivery->items as $i => $item)
                <tr>
                    <td class="center muted">{{ $i + 1 }}</td>
                    <td class="mono">{{ $item->product->product_code }}</td>
                    <td>{{ $item->product->product_name }}</td>
                    <td class="right">{{ $qty($item->quantity) }} {{ $item->product->unit_of_measure }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($delivery->notes)
        <p class="muted" style="margin-top: 10px; font-size: 8pt;">{{ __('Notes') }}: {!! nl2br(e($delivery->notes)) !!}</p>
    @endif

    <table class="sign" style="margin-top: 26px;">
        <tr>
            <td>
                <div class="label">{{ __('Prepared by') }}</div>
                <div class="sign-line">{{ $delivery->deliveredBy?->name ?? $delivery->createdBy?->name }}</div>
                <div class="muted">{{ __('Warehouse') }}</div>
            </td>
            <td style="padding-left: 20px;">
                <div class="label">{{ __('Driver') }}</div>
                <div class="sign-line">&nbsp;</div>
                <div class="muted"></div>
            </td>
            <td style="padding-left: 20px;">
                <div class="label">{{ __('Received by') }}</div>
                <div class="sign-line">&nbsp;</div>
                <div class="muted">{{ $customer->customer_name }}</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        {{ $company }} · {{ $delivery->delivery_number }} · {{ __('Generated :time', ['time' => now()->format('d/m/Y H:i')]) }}
    </div>
</body>
</html>
