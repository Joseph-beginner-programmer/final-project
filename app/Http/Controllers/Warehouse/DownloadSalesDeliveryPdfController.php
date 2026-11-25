<?php

namespace App\Http\Controllers\Warehouse;

use App\Http\Controllers\Controller;
use App\Models\SalesDelivery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class DownloadSalesDeliveryPdfController extends Controller
{
    public function __invoke(SalesDelivery $salesDelivery): Response
    {
        Gate::authorize('export', $salesDelivery);

        $salesDelivery->load(['salesOrder.customer', 'items.product', 'createdBy', 'deliveredBy']);

        return Pdf::loadView('warehouse.outbound.sales-deliveries.pdf', [
            'delivery' => $salesDelivery,
            'company' => config('company.name'),
            'logo' => $this->logo(),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->download($salesDelivery->delivery_number.'.pdf');
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
