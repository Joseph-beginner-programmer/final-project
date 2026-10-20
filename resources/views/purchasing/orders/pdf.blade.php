<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $purchaseOrder->po_number }}</title>
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
        .rule { border-bottom: 2px solid #c2410c; margin: 10px 0 14px; }
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
        $supplier = $purchaseOrder->supplier;
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
                <div class="title">{{ __('PURCHASE ORDER') }}</div>
                <div class="mono" style="font-size: 11pt; color: #c2410c; margin-top: 2px;">{{ $purchaseOrder->po_number }}</div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table>
        <tr>
            <td class="box" style="width: 55%;">
                <div class="label">{{ __('To') }}</div>
                <div style="font-size: 10.5pt; font-weight: bold; margin-top: 3px;">{{ $supplier->supplier_name }}</div>
                @if ($supplier->contact_person)
                    <div>{{ __('Attn.') }} {{ $supplier->contact_person }}</div>
                @endif
                @if ($supplier->address)
                    <div class="muted" style="margin-top: 2px;">{!! nl2br(e($supplier->address)) !!}</div>
                @endif
                @if ($supplier->phone || $supplier->email)
                    <div class="muted" style="margin-top: 2px;">{{ collect([$supplier->phone, $supplier->email])->filter()->implode(' · ') }}</div>
                @endif
            </td>
            <td style="width: 3%;"></td>
            <td class="box">
                <table>
                    <tr><td class="label" style="padding-bottom: 6px;">{{ __('Order Date') }}</td><td class="right" style="padding-bottom: 6px;">{{ $date($purchaseOrder->order_date) }}</td></tr>
                    <tr><td class="label" style="padding-bottom: 6px;">{{ __('Expected Delivery') }}</td><td class="right" style="padding-bottom: 6px;">{{ $date($purchaseOrder->expected_delivery_date) }}</td></tr>
                    <tr><td class="label">{{ __('Supplier Code') }}</td><td class="right mono">{{ $supplier->supplier_code }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <p style="margin: 14px 0 8px;">{{ __('Please supply the following goods according to the quantities and prices below:') }}</p>

    <table class="items">
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
            @foreach ($purchaseOrder->items as $i => $item)
                <tr>
                    <td class="center muted">{{ $i + 1 }}</td>
                    <td class="mono">{{ $item->product->product_code }}</td>
                    <td>{{ $item->product->product_name }}</td>
                    <td class="right">{{ $qty($item->quantity_ordered) }} {{ $item->product->unit_of_measure }}</td>
                    <td class="right">{{ $rupiah($item->unit_price) }}</td>
                    <td class="right">{{ $rupiah($item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 4px;">
        @if ($purchaseOrder->charges->isNotEmpty())
            <tr>
                <td style="width: 65%;"></td>
                <td>{{ __('Subtotal') }}</td>
                <td class="right">{{ $rupiah($purchaseOrder->items->sum('subtotal')) }}</td>
            </tr>
            @foreach ($purchaseOrder->charges as $charge)
                <tr>
                    <td></td>
                    <td class="muted">{{ $charge->description }}</td>
                    <td class="right">{{ $rupiah($charge->amount) }}</td>
                </tr>
            @endforeach
        @endif
        <tr class="grand">
            <td style="width: 65%;"></td>
            <td>{{ __('Total') }}</td>
            <td class="right">{{ $rupiah($purchaseOrder->total_amount) }}</td>
        </tr>
    </table>

    <p class="muted" style="margin-top: 14px; font-size: 8pt;">
        {{ __('Please state the PO number :number on the delivery note and invoice.', ['number' => $purchaseOrder->po_number]) }}
        {{ __('Prices exclude tax.') }}
    </p>

    <table class="sign" style="margin-top: 22px;">
        <tr>
            <td>
                <div class="label">{{ __('Prepared by') }}</div>
                <div class="sign-line">{{ $purchaseOrder->createdBy?->name }}</div>
                <div class="muted">{{ __('Purchasing') }}</div>
            </td>
            <td style="padding-left: 30px;">
                <div class="label">{{ __('Approved by') }}</div>
                <div class="sign-line">{{ $purchaseOrder->approvedBy?->name ?? '—' }}</div>
                <div class="muted">{{ $purchaseOrder->approved_at ? $date($purchaseOrder->approved_at) : '' }}</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        {{ $company }} · {{ $purchaseOrder->po_number }} · {{ __('Generated :time', ['time' => now()->format('d/m/Y H:i')]) }}
    </div>
</body>
</html>
