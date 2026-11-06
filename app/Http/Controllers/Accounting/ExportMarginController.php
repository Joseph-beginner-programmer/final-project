<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Support\Reports\Excel;
use App\Support\Reports\MarginReport;
use App\Support\Reports\ReportPdf;
use App\Support\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ExportMarginController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        Gate::authorize('reports.margin');
        abort_unless(in_array($format, ['pdf', 'excel'], true), 404);

        $period = ReportPeriod::normalize($request->query('period'));
        $start = ReportPeriod::start($period);
        $end = ReportPeriod::end($period);
        $byCustomer = $request->query('by') === 'customer';
        $report = new MarginReport($start, $end);
        $rows = $byCustomer ? $report->byCustomer() : $report->byProduct();
        $filename = 'margin-kotor-'.($byCustomer ? 'pelanggan' : 'produk').'-'.$period;
        $periodLabel = $start->translatedFormat('F Y');

        if ($format === 'excel') {
            $header = $byCustomer
                ? [__('Customer'), __('Revenue'), __('Cost of goods sold'), __('Gross profit'), __('Margin')]
                : [__('Product'), __('Quantity'), __('Revenue'), __('Cost of goods sold'), __('Gross profit'), __('Margin')];

            return Excel::download($filename.'.xls', $header, MarginReport::excel($rows, ! $byCustomer), [
                [__('Gross Margin Report')],
                [__('Period'), $periodLabel],
            ]);
        }

        return ReportPdf::download('accounting.reports.margin-pdf', [
            'rows' => $rows,
            'byCustomer' => $byCustomer,
            'periodLabel' => $periodLabel,
        ], $filename.'.pdf', 'landscape');
    }
}
