@php
    // display helper: 34.00 → 34, 1.25 → 1.25
    $qty = fn ($value) => str_contains((string) $value, '.') ? rtrim(rtrim((string) $value, '0'), '.') : (string) $value;
    $unit = $workOrder->product->unit_of_measure;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $workOrder->wo_number }} — {{ __('Work Order') }}</title>
    {{-- Self-contained styles: a printed document shouldn't depend on the app layout or the Vite build. --}}
    <style>
        @page { size: A4 portrait; margin: 12mm; }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #18181b;
            background: #f4f4f5;
        }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 24px;
            padding: 12mm;
            background: #fff;
        }
        .mono { font-family: Consolas, "Courier New", monospace; }
        .muted { color: #71717a; }
        .right { text-align: right; }

        /* toolbar — screen only */
        .toolbar {
            position: sticky; top: 0; z-index: 1;
            display: flex; justify-content: center; gap: 8px;
            padding: 10px; margin-bottom: 16px;
            background: #18181b;
        }
        .toolbar button {
            font: inherit; font-size: 10pt; cursor: pointer;
            padding: 6px 16px; border-radius: 6px; border: 1px solid #52525b;
            background: #27272a; color: #fff;
        }
        .toolbar button.primary { background: #C2540C; border-color: #C2540C; }

        /* header */
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #18181b; padding-bottom: 8px; }
        .company { font-size: 9pt; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #52525b; }
        .doc-title { margin: 2px 0 0; font-size: 18pt; font-weight: 700; letter-spacing: .02em; }
        .wo-number { font-size: 15pt; font-weight: 700; text-align: right; }
        .status { display: inline-block; margin-top: 4px; padding: 1px 8px; border: 1px solid #18181b; border-radius: 999px; font-size: 8pt; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }

        /* info grid */
        .info { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0; margin-top: 10px; border: 1px solid #d4d4d8; }
        .info div { padding: 6px 8px; border-right: 1px solid #d4d4d8; border-bottom: 1px solid #d4d4d8; }
        .info div:nth-child(3n) { border-right: 0; }
        .info div:nth-last-child(-n+3) { border-bottom: 0; }
        .label { display: block; font-size: 7.5pt; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #71717a; }
        .value { display: block; margin-top: 2px; font-size: 10.5pt; font-weight: 600; }
        .target { font-size: 14pt; }

        h2 { margin: 16px 0 6px; font-size: 9pt; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
        h2 small { font-weight: 400; letter-spacing: 0; text-transform: none; color: #71717a; }

        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #a1a1aa; padding: 5px 6px; vertical-align: top; }
        th { background: #f4f4f5; font-size: 7.5pt; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; text-align: left; }
        td.fill { background: #fff; height: 22px; } /* blank cell to be filled in by hand */
        tr.blank td { height: 26px; }
        .w-no { width: 26px; text-align: center; }

        /* one signature box, right-aligned like a letter sign-off */
        .signatures { display: flex; justify-content: flex-end; margin-top: 22px; }
        .sig { width: 70mm; border: 1px solid #a1a1aa; padding: 6px 8px; height: 90px; display: flex; flex-direction: column; justify-content: space-between; }
        .sig .line { border-top: 1px solid #18181b; padding-top: 3px; font-size: 8pt; color: #71717a; }

        .foot { margin-top: 14px; font-size: 7.5pt; color: #71717a; display: flex; justify-content: space-between; }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; }
            tr, .sig { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="primary" onclick="window.print()">{{ __('Print') }}</button>
        <button type="button" onclick="window.close()">{{ __('Close') }}</button>
    </div>

    <div class="sheet">
        {{-- Header --}}
        <div class="head">
            <div>
                <div class="company">{{ config('app.name') }}</div>
                <h1 class="doc-title">{{ __('WORK ORDER') }}</h1>
            </div>
            <div>
                <div class="wo-number mono">{{ $workOrder->wo_number }}</div>
                <div class="right"><span class="status">{{ __($workOrder->status->label()) }}</span></div>
            </div>
        </div>

        {{-- Plan --}}
        <div class="info">
            <div>
                <span class="label">{{ __('Product') }}</span>
                <span class="value">{{ $workOrder->product->product_name }}</span>
                <span class="mono muted">{{ $workOrder->product->product_code }}</span>
            </div>
            <div>
                <span class="label">{{ __('Target Output') }}</span>
                <span class="value target mono">{{ $qty($workOrder->quantity_target) }} {{ $unit }}</span>
            </div>
            <div>
                <span class="label">{{ __('Work Center') }}</span>
                <span class="value">{{ __($workOrder->workCenter->name) }}</span>
            </div>
            <div>
                <span class="label">{{ __('Planned Start') }}</span>
                <span class="value mono">{{ $workOrder->planned_start_date->format('d/m/Y') }}</span>
            </div>
            <div>
                <span class="label">{{ __('Planned End') }}</span>
                <span class="value mono">{{ $workOrder->planned_end_date->format('d/m/Y') }}</span>
            </div>
            <div>
                <span class="label">{{ __('Machine Hours') }}</span>
                <span class="value mono">{{ $qty($workOrder->planned_machine_hours) }} {{ __('hrs') }}</span>
            </div>
            <div>
                <span class="label">{{ __('Formula') }}</span>
                <span class="value mono">{{ $workOrder->productionFormula->formula_code }} v{{ $workOrder->productionFormula->version }}</span>
            </div>
            <div>
                <span class="label">{{ __('Created by') }}</span>
                <span class="value">{{ $workOrder->createdBy->name }}</span>
                <span class="mono muted">{{ $workOrder->created_at->format('d/m/Y') }}</span>
            </div>
            <div>
                <span class="label">{{ __('Printed') }}</span>
                <span class="value mono">{{ $workOrder->printed_at->format('d/m/Y H:i') }}</span>
            </div>
        </div>

        {{-- Materials --}}
        <h2>{{ __('Materials') }} <small>— {{ __('fill in the issued and used columns by hand') }}</small></h2>
        <table>
            <thead>
                <tr>
                    <th class="w-no">#</th>
                    <th>{{ __('Material') }}</th>
                    <th class="right" style="width: 18%">{{ __('Planned') }}</th>
                    <th class="right" style="width: 18%">{{ __('Issued') }}</th>
                    <th class="right" style="width: 18%">{{ __('Used') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($workOrder->materials as $material)
                    <tr>
                        <td class="w-no">{{ $loop->iteration }}</td>
                        <td>{{ $material->product->product_name }} <span class="mono muted">{{ $material->product->product_code }}</span></td>
                        <td class="right mono">{{ $qty($material->quantity_planned) }} {{ $material->product->unit_of_measure }}</td>
                        <td class="fill"></td>
                        <td class="fill"></td>
                    </tr>
                @endforeach
                {{-- spare lines for extra material added on the floor (e.g. to compensate damaged goods) --}}
                @for ($i = 0; $i < 2; $i++)
                    <tr class="blank"><td class="w-no"></td><td></td><td></td><td></td><td></td></tr>
                @endfor
            </tbody>
        </table>

        {{-- Workers --}}
        <h2>{{ __('Workers') }}</h2>
        <table>
            <thead>
                <tr>
                    <th class="w-no">#</th>
                    <th>{{ __('Worker') }}</th>
                    <th class="right" style="width: 18%">{{ __('Planned Hours') }}</th>
                    <th class="right" style="width: 18%">{{ __('Actual Hours') }}</th>
                    <th style="width: 18%">{{ __('Signature') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($workOrder->labors as $labor)
                    <tr>
                        <td class="w-no">{{ $loop->iteration }}</td>
                        <td>{{ $labor->employee->name }} <span class="mono muted">{{ $labor->employee->employee_code }}</span></td>
                        <td class="right mono">{{ $qty($labor->planned_hours) }} {{ __('hrs') }}</td>
                        <td class="fill"></td>
                        <td class="fill"></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Production log — what later gets entered as Production Results --}}
        <h2>{{ __('Production Log') }} <small>— {{ __('one line per date and shift') }}</small></h2>
        <table>
            <thead>
                <tr>
                    <th style="width: 14%">{{ __('Date') }}</th>
                    <th style="width: 9%">{{ __('Shift') }}</th>
                    <th class="right" style="width: 13%">{{ __('Good') }} ({{ $unit }})</th>
                    <th class="right" style="width: 13%">{{ __('Reject') }} ({{ $unit }})</th>
                    <th class="right" style="width: 13%">{{ __('Machine Hours') }}</th>
                    <th>{{ __('Notes') }}</th>
                    <th style="width: 12%">{{ __('Signature') }}</th>
                </tr>
            </thead>
            <tbody>
                @for ($i = 0; $i < 6; $i++)
                    <tr class="blank"><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                @endfor
            </tbody>
        </table>

        {{-- Single approval signature: the head of production signs the order off --}}
        <div class="signatures">
            <div class="sig">
                <span class="label">{{ __('Head of Production') }}</span>
                <span class="line">{{ __('Name & date') }}</span>
            </div>
        </div>

        <div class="foot">
            <span>{{ config('app.name') }} · {{ $workOrder->wo_number }}</span>
            <span>{{ __('Printed') }} {{ $workOrder->printed_at->format('d/m/Y H:i') }}</span>
        </div>
    </div>

    <script>
        // open the print dialog straight away; the toolbar stays for reprinting
        window.addEventListener('load', () => window.print());
    </script>
</body>
</html>
