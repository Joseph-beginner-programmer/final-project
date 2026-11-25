<?php

namespace App\Http\Controllers\Accounting;

use App\Actions\Sales\SaveSalesInvoiceDraftAction;
use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class DownloadSalesInvoicePdfController extends Controller
{
    public function __invoke(SalesInvoice $salesInvoice): Response
    {
        Gate::authorize('sales.invoice.export', $salesInvoice);

        $salesInvoice->load(['customer', 'salesOrder', 'salesDelivery']);

        return Pdf::loadView('accounting.sales-invoices.pdf', [
            'invoice' => $salesInvoice,
            'lines' => SaveSalesInvoiceDraftAction::lines($salesInvoice->salesDelivery),
            'company' => config('company.name'),
            'logo' => $this->logo(),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->download($salesInvoice->invoice_number.'.pdf');
    }

    private function logo(): ?string
    {
        $path = config('company.logo') ? public_path(config('company.logo')) : null;

        if (! $path || ! is_file($path)) {
            return null;
        }

        return 'data:'.mime_content_type($path).';base64,'.base64_encode(file_get_contents($path));
    }
}
