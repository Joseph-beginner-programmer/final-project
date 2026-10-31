<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Support\Reports\CashFlowReport;
use App\Support\Reports\Excel;
use App\Support\Reports\ReportPdf;
use App\Support\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ExportCashFlowController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        Gate::authorize('reports.finance');
        abort_unless(in_array($format, ['pdf', 'excel'], true), 404);

        $period = ReportPeriod::normalize($request->query('period'));
        $start = ReportPeriod::start($period);
        $end = ReportPeriod::end($period);
        $data = (new CashFlowReport($start, $end))->build();
        $filename = 'arus-kas-'.$period;
        $periodLabel = $start->translatedFormat('F Y');

        if ($format === 'excel') {
            return Excel::download($filename.'.xls', [__('Account'), __('Amount')], CashFlowReport::excel($data), [
                [__('Cash Flow Statement')],
                [__('Period'), $periodLabel],
            ]);
        }

        return ReportPdf::download('accounting.reports.cash-flow-pdf', [
            'data' => $data,
            'periodLabel' => $periodLabel,
        ], $filename.'.pdf', 'portrait');
    }
}
