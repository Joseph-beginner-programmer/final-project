<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Support\Reports\BalanceSheetReport;
use App\Support\Reports\Csv;
use App\Support\Reports\ReportPdf;
use App\Support\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ExportBalanceSheetController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        Gate::authorize('reports.finance');
        abort_unless(in_array($format, ['pdf', 'csv'], true), 404);

        $period = ReportPeriod::normalize($request->query('period'));
        $asOf = ReportPeriod::end($period);
        $data = (new BalanceSheetReport($asOf))->build();
        $filename = 'neraca-'.$period;
        $asOfLabel = $asOf->translatedFormat('d F Y');

        if ($format === 'csv') {
            return Csv::download($filename.'.csv', [__('Code'), __('Account'), __('Amount')], BalanceSheetReport::csv($data), [
                [__('Balance Sheet')],
                [__('As of'), $asOfLabel],
            ]);
        }

        return ReportPdf::download('accounting.reports.balance-sheet-pdf', [
            'data' => $data,
            'asOfLabel' => $asOfLabel,
        ], $filename.'.pdf', 'portrait');
    }
}
