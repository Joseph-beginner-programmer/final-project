<?php

namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * <<extend>> Mencetak Perintah Kerja: a print-ready page (the browser prints it or saves it as PDF).
 * A plain controller, not a Livewire page — there's nothing interactive, it's a document.
 */
class PrintWorkOrderController extends Controller
{
    public function __invoke(WorkOrder $workOrder): View
    {
        Gate::authorize('print', $workOrder);

        // guarded field → direct assignment; records the most recent print
        $workOrder->printed_at = now();
        $workOrder->save();

        $workOrder->load(['product', 'productionFormula', 'workCenter', 'materials.product', 'labors.employee', 'createdBy']);

        return view('production.work-orders.print', ['workOrder' => $workOrder]);
    }
}
