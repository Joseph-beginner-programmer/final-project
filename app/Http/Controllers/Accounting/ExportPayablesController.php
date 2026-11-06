<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Support\Reports\AgingReport;
use App\Support\Reports\Excel;
use App\Support\Reports\ReportPdf;
use App\Support\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ExportPayablesController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        Gate::authorize('reports.payables');
        abort_unless(in_array($format, ['pdf', 'excel'], true), 404);

        $period = ReportPeriod::normalize($request->query('period'));
        $end = ReportPeriod::end($period);
        $asOf = $end->lt(today()) ? $end : today();
        $report = AgingReport::payables($asOf);
        $rows = $report->rows();
        $summary = $report->summary();
        $filename = 'utang-usaha-'.$period;
        $dateLabel = $asOf->translatedFormat('d M Y');

        if ($format === 'excel') {
            return Excel::download($filename.'.xls', [__('Supplier'), __('Not yet due'), __('1-30 days'), __('31-60 days'), __('61-90 days'), __('Over 90 days'), __('Total')], AgingReport::excel($rows), [
                [__('Payables Aging Report')],
                [__('As of'), $dateLabel],
            ]);
        }

        return ReportPdf::download('accounting.reports.aging-pdf', [
            'rows' => $rows,
            'summary' => $summary,
            'partyLabel' => __('Supplier'),
            'title' => __('PAYABLES AGING REPORT'),
            'dateLabel' => $dateLabel,
        ], $filename.'.pdf', 'landscape');
    }
}
