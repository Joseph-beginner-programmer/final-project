<?php

namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Support\Reports\Excel;
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
        abort_unless(in_array($format, ['pdf', 'excel'], true), 404);

        $period = ReportPeriod::normalize($request->query('period'));
        $type = ReportPeriod::type($request->query('type'), InventoryReport::allTypes());
        $start = ReportPeriod::start($period);
        $report = new InventoryReport($start, ReportPeriod::end($period), $type);
        $withValue = Gate::allows('reports.inventory-value');
        $filename = 'persediaan-gudang-'.$period.($type ? '-'.$type->value : '');
        $typeLabel = $type?->label() ?? __('Raw material, WIP and finished goods');

        if ($format === 'excel') {
            $excel = $report->excel($withValue);

            return Excel::download($filename.'.xls', $excel['header'], $excel['rows'], [
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
