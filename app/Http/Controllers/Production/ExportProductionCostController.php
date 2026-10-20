<?php

namespace App\Http\Controllers\Production;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Support\Reports\Csv;
use App\Support\Reports\ProductionCostReport;
use App\Support\Reports\ReportPdf;
use App\Support\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ExportProductionCostController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        Gate::authorize('reports.production');
        abort_unless(in_array($format, ['pdf', 'csv'], true), 404);

        $period = ReportPeriod::normalize($request->query('period'));
        $type = ReportPeriod::type($request->query('type'), [ProductType::FinishedGoods, ProductType::Wip]);
        $start = ReportPeriod::start($period);
        $report = new ProductionCostReport($start, ReportPeriod::end($period), $type);
        $filename = 'biaya-produksi-'.$period.($type ? '-'.$type->value : '');
        $typeLabel = $type?->label() ?? __('All');

        if ($format === 'csv') {
            $csv = $report->csv();

            return Csv::download($filename.'.csv', $csv['header'], $csv['rows'], [
                [__('Production Cost Report')],
                [__('Period'), $start->translatedFormat('F Y')],
                [__('Product type'), $typeLabel],
            ]);
        }

        $rows = $report->rows();

        return ReportPdf::download('production.reports.cost-pdf', [
            'rows' => $rows,
            'totals' => $report->sum($rows),
            'products' => $report->productSummary($rows),
            'periodLabel' => $start->translatedFormat('F Y'),
            'typeLabel' => $typeLabel,
        ], $filename.'.pdf');
    }
}
