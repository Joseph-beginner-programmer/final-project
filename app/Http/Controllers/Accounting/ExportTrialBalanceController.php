<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Support\Reports\Csv;
use App\Support\Reports\ReportPdf;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\TrialBalanceReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ExportTrialBalanceController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        Gate::authorize('reports.finance');
        abort_unless(in_array($format, ['pdf', 'csv'], true), 404);

        $period = ReportPeriod::normalize($request->query('period'));
        $start = ReportPeriod::start($period);
        $report = new TrialBalanceReport($start, ReportPeriod::end($period));
        $rows = $report->rows($request->boolean('all'));
        $totals = TrialBalanceReport::totals($rows);
        $filename = 'neraca-saldo-'.$period;

        if ($format === 'csv') {
            return Csv::download($filename.'.csv', [
                __('Code'), __('Account'), __('Category'),
                __('Opening debit'), __('Opening credit'), __('Debit movement'), __('Credit movement'), __('Closing debit'), __('Closing credit'),
            ], [
                ...$report->csv($rows),
                ['', __('Total'), '', ...array_map(fn ($v) => Csv::number($v), array_values($totals))],
            ], [
                [__('Trial Balance')],
                [__('Period'), $start->translatedFormat('F Y')],
            ]);
        }

        return ReportPdf::download('accounting.reports.trial-balance-pdf', [
            'rows' => $rows,
            'totals' => $totals,
            'balanced' => TrialBalanceReport::isBalanced($totals),
            'periodLabel' => $start->translatedFormat('F Y'),
        ], $filename.'.pdf');
    }
}
