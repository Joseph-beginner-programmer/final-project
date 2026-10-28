<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Support\Reports\Excel;
use App\Support\Reports\PurchaseOrderReport;
use App\Support\Reports\ReportPdf;
use App\Support\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ExportPurchaseOrderController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        Gate::authorize('reports.purchasing');
        abort_unless(in_array($format, ['pdf', 'excel'], true), 404);

        $period = ReportPeriod::normalize($request->query('period'));
        $start = ReportPeriod::start($period);
        $report = new PurchaseOrderReport($start, ReportPeriod::end($period));
        $filename = 'laporan-po-'.$period;

        if ($format === 'excel') {
            $excel = $report->excel();

            return Excel::download($filename.'.xls', $excel['header'], $excel['rows'], [
                [__('Purchase Order Report')],
                [__('Period'), $start->translatedFormat('F Y')],
            ]);
        }

        $rows = $report->rows();

        return ReportPdf::download('purchasing.reports.orders-pdf', [
            'rows' => $rows,
            'totals' => $report->sum($rows),
            'statuses' => $report->statusSummary($rows),
            'periodLabel' => $start->translatedFormat('F Y'),
        ], $filename.'.pdf', 'landscape');
    }
}
