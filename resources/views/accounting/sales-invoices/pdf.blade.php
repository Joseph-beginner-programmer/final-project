<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
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
        .rule { border-bottom: 2px solid #15803d; margin: 10px 0 14px; }
        .box { border: 1px solid #e4e4e7; padding: 8px 10px; vertical-align: top; }
        .items th { background: #f4f4f5; font-size: 7pt; font-weight: bold; text-transform: uppercase; letter-spacing: .05em; color: #52525b; padding: 6px 6px; border-bottom: 1px solid #d4d4d8; }
        .items td { padding: 6px 6px; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
        .totals td { padding: 5px 6px; }
        .grand td { border-top: 2px solid #18181b; font-weight: bold; font-size: 10pt; }
        .sign td { width: 50%; vertical-align: top; padding-top: 6px; }
        .sign-line { border-top: 1px solid #a1a1aa; margin-top: 52px; padding-top: 4px; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7pt; color: #a1a1aa; }
    </style>
</head>
<body>
    @php
        $qty = fn ($v) => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v;
        $rupiah = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $date = fn ($d) => $d?->locale(app()->getLocale())->translatedFormat('d F Y') ?? '—';
        $customer = $invoice->customer;
        $balance = $invoice->balance();
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
                <div class="title">{{ __('SALES INVOICE') }}</div>
                <div class="mono" style="font-size: 11pt; color: #15803d; margin-top: 2px;">{{ $invoice->invoice_number }}</div>
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
                    <tr><td class="label" style="padding-bottom: 6px;">{{ __('Invoice date') }}</td><td class="right" style="padding-bottom: 6px;">{{ $date($invoice->invoice_date) }}</td></tr>
                    <tr><td class="label" style="padding-bottom: 6px;">{{ __('Due date') }}</td><td class="right" style="padding-bottom: 6px;">{{ $date($invoice->due_date) }}</td></tr>
                    <tr><td class="label" style="padding-bottom: 6px;">{{ __('Sales Order') }}</td><td class="right mono" style="padding-bottom: 6px;">{{ $invoice->salesOrder->so_number }}</td></tr>
                    <tr><td class="label">{{ __('Customer Code') }}</td><td class="right mono">{{ $customer->customer_code }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items" style="margin-top: 14px;">
        <thead>
            <tr>
                <th class="center" style="width: 5%;">{{ __('No') }}</th>
                <th style="text-align: left; width: 13%;">{{ __('Code') }}</th>
                <th style="text-align: left;">{{ __('Item') }}</th>
                <th class="right" style="width: 13%;">{{ __('Qty') }}</th>
                <th class="right" style="width: 17%;">{{ __('Unit Price') }}</th>
                <th class="right" style="width: 18%;">{{ __('Subtotal') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $i => $line)
                <tr>
                    <td class="center muted">{{ $i + 1 }}</td>
                    <td class="mono">{{ $line['product_code'] }}</td>
                    <td>{{ $line['product_name'] }}</td>
                    <td class="right">{{ $qty($line['quantity']) }} {{ $line['unit'] }}</td>
                    <td class="right">{{ $rupiah($line['unit_price']) }}</td>
                    <td class="right">{{ $rupiah($line['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 4px;">
        <tr>
            <td style="width: 65%;"></td>
            <td>{{ __('Goods amount') }}</td>
            <td class="right">{{ $rupiah($invoice->goods_amount) }}</td>
        </tr>
        @if (bccomp((string) $invoice->discount_amount, '0', 2) > 0)
            <tr>
                <td></td>
                <td class="muted">{{ __('Discount') }} ({{ str_replace('.', ',', (string) $invoice->discount_percent) }}%)</td>
                <td class="right">−{{ $rupiah($invoice->discount_amount) }}</td>
            </tr>
        @endif
        @if (bccomp((string) $invoice->charges_amount, '0', 2) > 0)
            <tr>
                <td></td>
                <td class="muted">{{ __('Other charges') }}</td>
                <td class="right">{{ $rupiah($invoice->charges_amount) }}</td>
            </tr>
        @endif
        @if (bccomp((string) $invoice->tax_amount, '0', 2) > 0)
            <tr>
                <td></td>
                <td class="muted">{{ __('Tax') }} ({{ str_replace('.', ',', (string) $invoice->tax_rate) }}%)</td>
                <td class="right">{{ $rupiah($invoice->tax_amount) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td style="width: 65%;"></td>
            <td>{{ __('Total') }}</td>
            <td class="right">{{ $rupiah($invoice->total_amount) }}</td>
        </tr>
        @if ($invoice->status->isPosted() && bccomp((string) $invoice->amount_paid, '0', 2) > 0)
            <tr>
                <td></td>
                <td class="muted">{{ __('Paid') }}</td>
                <td class="right">−{{ $rupiah($invoice->amount_paid) }}</td>
            </tr>
            <tr class="grand">
                <td></td>
                <td>{{ __('Balance due') }}</td>
                <td class="right">{{ $rupiah($balance) }}</td>
            </tr>
        @endif
    </table>

    <table class="sign" style="margin-top: 22px;">
        <tr>
            <td>
                <div class="label">{{ __('Prepared by') }}</div>
                <div class="sign-line">&nbsp;</div>
                <div class="muted">{{ __('Accounting') }}</div>
            </td>
            <td style="padding-left: 30px;">
                <div class="label">{{ __('Received by') }}</div>
                <div class="sign-line">&nbsp;</div>
                <div class="muted">{{ $customer->customer_name }}</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        {{ $company }} · {{ $invoice->invoice_number }} · {{ __('Generated :time', ['time' => now()->format('d/m/Y H:i')]) }}
    </div>
</body>
</html>
