<?php

namespace App\Http\Controllers\Production;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PrintWorkOrderController extends Controller
{
    public function __invoke(WorkOrder $workOrder): View
    {
        Gate::authorize('print', $workOrder);

        $workOrder->printed_at = now();
        $workOrder->save();

        $workOrder->load(['product', 'productionFormula', 'workCenter', 'materials.product', 'labors.employee', 'createdBy']);

        return view('production.work-orders.print', ['workOrder' => $workOrder]);
    }
}
