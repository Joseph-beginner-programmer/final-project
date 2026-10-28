<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Support\Reports\Excel;
use App\Support\Reports\PurchasePriceVarianceReport;
use App\Support\Reports\ReportPdf;
use App\Support\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ExportPurchasePriceVarianceController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        Gate::authorize('reports.purchasing');
        abort_unless(in_array($format, ['pdf', 'excel'], true), 404);

        $period = ReportPeriod::normalize($request->query('period'));
        $start = ReportPeriod::start($period);
        $report = new PurchasePriceVarianceReport($start, ReportPeriod::end($period));
        $filename = 'selisih-harga-pembelian-'.$period;

        if ($format === 'excel') {
            $excel = $report->excel();

            return Excel::download($filename.'.xls', $excel['header'], $excel['rows'], [
                [__('Purchase Price Variance Report')],
                [__('Period'), $start->translatedFormat('F Y')],
            ]);
        }

        $rows = $report->rows();

        return ReportPdf::download('purchasing.reports.price-variance-pdf', [
            'rows' => $rows,
            'totals' => $report->sum($rows),
            'suppliers' => $report->supplierSummary($rows),
            'periodLabel' => $start->translatedFormat('F Y'),
        ], $filename.'.pdf', 'landscape');
    }
}
