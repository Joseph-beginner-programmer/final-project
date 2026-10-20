<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class DownloadPurchaseOrderPdfController extends Controller
{
    public function __invoke(PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('export', $purchaseOrder);

        $purchaseOrder->load(['supplier', 'items.product', 'charges', 'createdBy', 'approvedBy']);

        return Pdf::loadView('purchasing.orders.pdf', [
            'purchaseOrder' => $purchaseOrder,
            'company' => config('company.name'),
            'logo' => $this->logo(),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->download($purchaseOrder->po_number.'.pdf');
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
