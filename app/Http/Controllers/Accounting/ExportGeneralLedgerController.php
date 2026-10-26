<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\Reports\Csv;
use App\Support\Reports\GeneralLedgerReport;
use App\Support\Reports\ReportPdf;
use App\Support\Reports\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ExportGeneralLedgerController extends Controller
{
    public function __invoke(Request $request, string $format): Response
    {
        Gate::authorize('reports.finance');
        abort_unless(in_array($format, ['pdf', 'csv'], true), 404);

        $account = Account::findOrFail((int) $request->query('account'));
        $period = ReportPeriod::normalize($request->query('period'));
        $start = ReportPeriod::start($period);
        $report = new GeneralLedgerReport($account, $start, ReportPeriod::end($period));
        $rows = $report->rows();
        $filename = 'buku-besar-'.$account->account_code.'-'.$period;

        if ($format === 'csv') {
            $summary = $report->summary($rows);

            return Csv::download($filename.'.csv', [
                __('Date'), __('Journal'), __('Description'), __('Counter account'), __('Debit'), __('Credit'), __('Running balance'),
            ], [
                ...$report->csv($rows),
                ['', '', __('Closing balance'), '', Csv::number($summary['debit']), Csv::number($summary['credit']), Csv::number($summary['closing'])],
            ], [
                [__('General Ledger')],
                [__('Account'), $account->account_code.' '.$account->account_name],
                [__('Normal balance'), $account->normal_balance->label()],
                [__('Period'), $start->translatedFormat('F Y')],
            ]);
        }

        return ReportPdf::download('accounting.reports.ledger-pdf', [
            'account' => $account,
            'rows' => $rows,
            'summary' => $report->summary($rows),
            'periodLabel' => $start->translatedFormat('F Y'),
        ], $filename.'.pdf', 'portrait');
    }
}
