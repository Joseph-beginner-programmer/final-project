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

class ExportReceivablesController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        Gate::authorize('reports.receivables');
        abort_unless(in_array($format, ['pdf', 'excel'], true), 404);

        $period = ReportPeriod::normalize($request->query('period'));
        $end = ReportPeriod::end($period);
        $asOf = $end->lt(today()) ? $end : today();
        $report = AgingReport::receivables($asOf);
        $rows = $report->rows();
        $summary = $report->summary();
        $filename = 'piutang-usaha-'.$period;
        $dateLabel = $asOf->translatedFormat('d M Y');

        if ($format === 'excel') {
            return Excel::download($filename.'.xls', [__('Customer'), __('Not yet due'), __('1-30 days'), __('31-60 days'), __('61-90 days'), __('Over 90 days'), __('Total')], AgingReport::excel($rows), [
                [__('Receivables Aging Report')],
                [__('As of'), $dateLabel],
            ]);
        }

        return ReportPdf::download('accounting.reports.aging-pdf', [
            'rows' => $rows,
            'summary' => $summary,
            'partyLabel' => __('Customer'),
            'title' => __('RECEIVABLES AGING REPORT'),
            'dateLabel' => $dateLabel,
        ], $filename.'.pdf', 'landscape');
    }
}
