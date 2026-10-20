<?php

namespace App\Http\Controllers\Production;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Support\Reports\Csv;
use App\Support\Reports\InventoryReport;
use App\Support\Reports\ReportPdf;
use App\Support\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ExportInventoryController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        Gate::authorize('reports.production');
        abort_unless(in_array($format, ['pdf', 'csv'], true), 404);

        $period = ReportPeriod::normalize($request->query('period'));
        $type = ReportPeriod::type($request->query('type'), [ProductType::RawMaterial, ProductType::Wip]);
        $start = ReportPeriod::start($period);
        $report = new InventoryReport($start, ReportPeriod::end($period), $type);
        $withValue = Gate::allows('reports.inventory-value');
        $filename = 'persediaan-gudang-'.$period.($type ? '-'.$type->value : '');
        $typeLabel = $type?->label() ?? __('Raw material + WIP');

        if ($format === 'csv') {
            $csv = $report->csv($withValue);

            return Csv::download($filename.'.csv', $csv['header'], $csv['rows'], [
                [__('Warehouse Inventory Report')],
                [__('Period'), $start->translatedFormat('F Y')],
                [__('Items'), $typeLabel],
            ]);
        }

        $rows = $report->rows();

        return ReportPdf::download('production.reports.inventory-pdf', [
            'rows' => $rows,
            'withValue' => $withValue,
            'totals' => $report->valueTotals($rows),
            'periodLabel' => $start->translatedFormat('F Y'),
            'typeLabel' => $typeLabel,
        ], $filename.'.pdf', $withValue ? 'landscape' : 'portrait');
    }
}
