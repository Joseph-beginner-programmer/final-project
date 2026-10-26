<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Support\Reports\Csv;
use App\Support\Reports\IncomeStatementReport;
use App\Support\Reports\ReportPdf;
use App\Support\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ExportIncomeStatementController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        Gate::authorize('reports.finance');
        abort_unless(in_array($format, ['pdf', 'csv'], true), 404);

        $period = ReportPeriod::normalize($request->query('period'));
        $start = ReportPeriod::start($period);
        $report = new IncomeStatementReport($start, ReportPeriod::end($period));
        $data = $report->build();
        $filename = 'laba-rugi-'.$period;
        $monthLabel = $start->translatedFormat('F Y');
        $ytdLabel = $report->yearStart()->translatedFormat('M').' – '.$start->translatedFormat('M Y');

        if ($format === 'csv') {
            return Csv::download($filename.'.csv', [__('Code'), __('Account'), $monthLabel, $ytdLabel], IncomeStatementReport::csv($data), [
                [__('Income Statement')],
                [__('Period'), $monthLabel],
            ]);
        }

        return ReportPdf::download('accounting.reports.income-statement-pdf', [
            'data' => $data,
            'monthLabel' => $monthLabel,
            'ytdLabel' => $ytdLabel,
        ], $filename.'.pdf', 'portrait');
    }
}
