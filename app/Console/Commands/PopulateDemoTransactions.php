<?php

namespace App\Console\Commands;

use App\Actions\Accounting\PayWagesAction;
use App\Actions\Accounting\PostJournalEntryAction;
use App\Actions\Accounting\RecordOverheadExpenseAction;
use App\Actions\Production\CreateWorkOrderAction;
use App\Actions\Production\PostProductionResultAction;
use App\Actions\Production\ReleaseWorkOrderAction;
use App\Actions\Production\SaveProductionResultDraftAction;
use App\Actions\Purchasing\ApprovePurchaseOrderAction;
use App\Actions\Purchasing\CreatePurchaseOrderAction;
use App\Actions\Purchasing\PostPurchaseInvoiceAction;
use App\Actions\Purchasing\RecordPurchasePaymentAction;
use App\Actions\Purchasing\SavePurchaseInvoiceDraftAction;
use App\Actions\Purchasing\SubmitPurchaseOrderForApprovalAction;
use App\Actions\Sales\ConfirmSalesOrderAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\RecordSalesPaymentAction;
use App\Actions\Sales\SaveSalesInvoiceDraftAction;
use App\Actions\Sales\SaveSalesOrderDraftAction;
use App\Actions\Warehouse\PostProductionReceiptAction;
use App\Actions\Warehouse\PostSalesDeliveryAction;
use App\Actions\Warehouse\SaveProductionReceiptDraftAction;
use App\Actions\Warehouse\SaveSalesDeliveryDraftAction;
use App\DTO\Production\CreateWorkOrderData;
use App\DTO\Production\ProductionResultData;
use App\DTO\Purchasing\CreatePurchaseOrderData;
use App\DTO\Purchasing\PurchaseInvoiceData;
use App\DTO\Purchasing\ReceivePurchaseOrderBatchData;
use App\DTO\Sales\SalesOrderData;
use App\Enums\OverheadCategory;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTerm;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Account;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductionFormula;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceiptBatch;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\WorkOrder;
use App\Services\Purchasing\ReceivePurchaseOrderBatchService;
use App\Support\OverheadPeriod;
use App\Support\UnpaidWages;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-off: generates realistic historical transactions against REAL production
 * master data (products/customers/suppliers/employees/accounts already in the DB)
 * so reports and dashboards have believable volume for a thesis demo.
 *
 * Not a seeder: every document is created through the app's real Actions
 * (CreatePurchaseOrderAction, CreateWorkOrderAction, etc.), so FIFO lots,
 * journal entries, and every derived field come out exactly as if a real
 * user had clicked through the UI. No new tables, no master data touched.
 *
 * Safe-by-design choices:
 *  - Each document is wrapped in its own try/catch and skipped (not fatal)
 *    if something about it doesn't fit - no single edge case aborts the run.
 *  - Never closes an Overhead or PPN period (that's final/locked); only
 *    records actual overhead expenses and pays accrued wages, both of which
 *    stay correctable like any other transaction.
 *  - Only ever INSERTs new documents; never touches the PO/SO/WO rows that
 *    already exist from real UAT testing.
 */
class PopulateDemoTransactions extends Command
{
    protected $signature = 'demo:populate
        {--start=2026-08-01 : first day to simulate}
        {--end=2026-11-07 : last day to simulate (keep before real transactions start)}
        {--force : actually write - without this the command only prints the plan}';

    protected $description = 'Generate realistic historical transactions against real production master data';

    /** @var array<string,int> */
    protected array $users = [];

    /** @var list<int> */
    protected array $cashAccountIds = [];

    /** @var array<string,array{id:int,formula:?ProductionFormula}> */
    protected array $fg = [];

    /** @var list<array{id:int,weight:float,terms:PaymentTerm,discount:string,pay_after:array{0:int,1:int}}> */
    protected array $customers = [];

    /** @var list<array{work_order:int,post_on:string}> */
    protected array $openWorkOrders = [];

    /** @var array<string,list<callable>> */
    protected array $events = [];

    protected Carbon $today;

    protected Carbon $rangeStart;

    protected string $attachmentPath = 'purchase-order-receipts/demo-surat-jalan.pdf';

    protected int $skipped = 0;

    public function handle(): int
    {
        $start = Carbon::parse($this->option('start'))->startOfDay();
        $end = Carbon::parse($this->option('end'))->startOfDay();
        $this->today = $end->copy();
        $this->rangeStart = $start->copy();

        $this->resolveMasterData();

        if (! $this->option('force')) {
            $this->info('DRY PLAN (pass --force to actually write):');
            $this->line('  Range: '.$start->toDateString().' to '.$end->toDateString());
            $this->line('  FG lines: '.implode(', ', array_keys($this->fg)));
            $this->line('  Customers: '.count($this->customers));
            $this->line('  Users: '.json_encode($this->users));
            $this->line('  Cash accounts: '.implode(', ', $this->cashAccountIds));

            return self::SUCCESS;
        }

        \Illuminate\Support\Facades\Storage::disk('local')->put($this->attachmentPath, "%PDF-1.4\n% demo surat jalan\n");

        $before = $this->counts();
        mt_srand(2026);

        $this->travel($start->copy()->subDay());
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $this->travel($day);
            $this->runDay($day);
        }

        Date::setTestNow();

        $after = $this->counts();
        $this->info('Done. Skipped '.$this->skipped.' documents (see storage/logs/laravel.log for why).');
        foreach ($after as $key => $value) {
            $this->line('  '.$key.': '.$before[$key].' -> '.$value.' (+'.($value - $before[$key]).')');
        }

        return self::SUCCESS;
    }

    protected function counts(): array
    {
        return [
            'purchase_orders' => PurchaseOrder::count(),
            'purchase_invoices' => PurchaseInvoice::count(),
            'sales_orders' => SalesOrder::count(),
            'sales_invoices' => SalesInvoice::count(),
            'work_orders' => WorkOrder::count(),
        ];
    }

    protected function resolveMasterData(): void
    {
        $byRole = \App\Models\User::whereIn('id', [1, 5, 19, 20, 21, 22, 23, 24])->get()->keyBy('id');
        $this->users = [
            'manager' => 1,
            'accounting' => 1,
            'production' => $byRole->has(5) ? 5 : 1,
            'purchasing' => $byRole->has(22) ? 22 : 1,
            'sales' => array_values(array_intersect([23, 24], $byRole->keys()->all())) ?: [1],
            'warehouse' => array_values(array_intersect([19, 20, 21], $byRole->keys()->all())) ?: [1],
        ];

        $this->cashAccountIds = Account::where('is_cash', true)->where('is_active', true)->pluck('id')->all();
        if ($this->cashAccountIds === []) {
            throw new \RuntimeException('No is_cash account found - tick "Akun kas" on at least one account first.');
        }

        $fgCodes = ['FG-001', 'FG-002', 'FG-003', 'FG-004', 'FG-005', 'FG-006'];
        foreach (Product::whereIn('product_code', $fgCodes)->get() as $product) {
            $formula = ProductionFormula::where('product_id', $product->id)->where('is_active', true)->first();
            if ($formula) {
                $this->fg[$product->product_code] = ['id' => $product->id, 'formula' => $formula];
            }
        }

        $weights = [0.22, 0.18, 0.15, 0.12, 0.10, 0.08, 0.06, 0.04, 0.03, 0.02, 0.025, 0.02, 0.015, 0.015, 0.015];
        $terms = [PaymentTerm::Cash, PaymentTerm::Net14, PaymentTerm::Net30, PaymentTerm::Net30, PaymentTerm::Net60];
        $customerIds = range(1, 15);
        foreach ($customerIds as $i => $id) {
            $this->customers[] = [
                'id' => $id,
                'weight' => $weights[$i] ?? 0.02,
                'terms' => $terms[$i % count($terms)],
                'discount' => $i % 7 === 0 ? '2' : '0',
                'pay_after' => match (true) {
                    $i % 5 === 0 => [45, 75],
                    $i % 3 === 0 => [10, 25],
                    default => [-2, 8],
                },
            ];
        }
    }

    protected function travel(Carbon $day): void
    {
        Date::setTestNow($day->copy()->setTime(9, 0));
    }

    protected function skip(string $what, Throwable $e): void
    {
        $this->skipped++;
        Log::warning('[demo:populate] skipped '.$what.': '.$e->getMessage());
    }

    protected function at(Carbon $day, callable $event): void
    {
        if ($day->lte($this->today)) {
            $this->events[$day->toDateString()][] = $event;
        }
    }

    protected function runDay(Carbon $day): void
    {
        foreach ($this->events[$day->toDateString()] ?? [] as $event) {
            try {
                $event();
            } catch (Throwable $e) {
                $this->skip('deferred event on '.$day->toDateString(), $e);
            }
        }
        unset($this->events[$day->toDateString()]);

        $this->finishWorkOrders($day);

        if ($day->day === 1 && $day->copy()->subMonthNoOverflow()->format('Y-m') >= $this->rangeStart->format('Y-m')) {
            try {
                $this->monthlyOverheadAndWages($day->copy()->subMonthNoOverflow());
            } catch (Throwable $e) {
                $this->skip('monthly overhead/wages for '.$day->copy()->subMonthNoOverflow()->format('Y-m'), $e);
            }
        }

        match ($day->dayOfWeekIso) {
            1 => [$this->takeOrders($day), $this->purchaseRawMaterials($day)],
            3 => [$this->receivePurchases($day), $this->startMolding($day)],
            4 => $this->deliver($day),
            5 => $this->startAssembly($day),
            default => null,
        };
    }

    // --- master-data helpers -------------------------------------------------

    protected function stock(int $productId): float
    {
        return (float) Product::whereKey($productId)->value('current_stock');
    }

    protected function onOrder(int $productId): float
    {
        return (float) DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_order_items.product_id', $productId)
            ->whereIn('purchase_orders.status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->sum(DB::raw('purchase_order_items.quantity_ordered - purchase_order_items.quantity_received'));
    }

    protected function openSalesQty(int $productId): float
    {
        return (float) DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->where('sales_order_items.product_id', $productId)
            ->whereNotIn('sales_orders.status', [SalesOrderStatus::Draft->value, SalesOrderStatus::Cancelled->value])
            ->selectRaw('SUM(GREATEST(sales_order_items.quantity_ordered - sales_order_items.quantity_delivered, 0)) as open')
            ->value('open') ?? 0.0;
    }

    /**
     * Immediate formula items split into [wip components, direct raw materials].
     *
     * @return array{0: list<array{product_id:int,qty:float}>, 1: list<array{product_id:int,qty:float}>}
     */
    protected function splitFormulaItems(ProductionFormula $formula): array
    {
        $wip = [];
        $raw = [];
        foreach ($formula->items as $item) {
            $material = Product::find($item->material_product_id);
            // Plain float division here, not scaleMaterialQuantity($qty, '1') - that helper's
            // bcdiv(scale: 2) truncates trace-quantity items (e.g. colorants) straight to 0.00,
            // which silently zeroes their demand forever. Fine for this planning heuristic;
            // the real Actions still compute final persisted quantities at full precision
            // against the actual batch size, never against a target of '1'.
            $qty = (float) $item->quantity / max((float) $formula->output_quantity, 0.0001);
            if ($material->type->value === 'wip') {
                $wip[] = ['product_id' => $material->id, 'qty' => $qty];
            } else {
                $raw[] = ['product_id' => $material->id, 'qty' => $qty];
            }
        }

        return [$wip, $raw];
    }

    protected function supplierPrice(int $productId): ?object
    {
        return DB::table('product_supplier')->where('product_id', $productId)->first();
    }

    protected function roundLot(float $qty, string $unit): float
    {
        $step = match ($unit) {
            'Kg' => 25.0,
            'Roll' => 5.0,
            'Pcs' => 50.0,
            default => 10.0,
        };

        return ceil($qty / $step) * $step;
    }

    protected function decimal(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    protected function random(float $min, float $max): float
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }

    // --- Monday: sales orders + raw material purchasing ----------------------

    protected function takeOrders(Carbon $day): void
    {
        $fgCodes = array_keys($this->fg);
        $pickCount = mt_rand(3, 6);
        $picked = (array) array_rand($this->customers, min($pickCount, count($this->customers)));

        foreach ($picked as $index) {
            $customer = $this->customers[$index];
            $lineCount = mt_rand(1, 3);
            $lines = [];
            foreach ((array) array_rand($this->fg, min($lineCount, count($this->fg))) as $code) {
                $quantity = round($this->random(20, 100) / 10) * 10;
                $product = Product::find($this->fg[$code]['id']);
                $lines[] = ['productId' => $product->id, 'quantity' => $this->decimal($quantity), 'unitPrice' => (string) $product->selling_price];
            }
            if ($lines === []) {
                continue;
            }

            try {
                $order = app(SaveSalesOrderDraftAction::class)->handle(new SalesOrderData(
                    customerId: $customer['id'],
                    salespersonId: $this->users['sales'][array_rand($this->users['sales'])],
                    orderDate: $day->toDateString(),
                    expectedDeliveryDate: $day->copy()->addDays(3)->toDateString(),
                    paymentTerms: $customer['terms'],
                    lines: $lines,
                    discountPercent: $customer['discount'],
                ), $this->users['sales'][array_rand($this->users['sales'])]);
                app(ConfirmSalesOrderAction::class)->handle($order, $this->users['sales'][array_rand($this->users['sales'])]);
            } catch (Throwable $e) {
                $this->skip('sales order for customer '.$customer['id'].' on '.$day->toDateString(), $e);
            }
        }
    }

    protected function purchaseRawMaterials(Carbon $day): void
    {
        $needs = [];
        foreach ($this->fg as $code => $info) {
            $openQty = $this->openSalesQty($info['id']) + 60;
            [$wipItems, $directRaw] = $this->splitFormulaItems($info['formula']);

            foreach ($directRaw as $item) {
                $needs[$item['product_id']] = ($needs[$item['product_id']] ?? 0) + $item['qty'] * $openQty;
            }
            foreach ($wipItems as $wipItem) {
                $wipFormula = ProductionFormula::where('product_id', $wipItem['product_id'])->where('is_active', true)->first();
                if (! $wipFormula) {
                    continue;
                }
                [, $wipRaw] = $this->splitFormulaItems($wipFormula);
                foreach ($wipRaw as $item) {
                    $needs[$item['product_id']] = ($needs[$item['product_id']] ?? 0) + $item['qty'] * $wipItem['qty'] * $openQty;
                }
            }
        }

        $bySupplier = [];
        foreach ($needs as $productId => $qty) {
            $shortfall = $qty * 1.3 - $this->stock($productId) - $this->onOrder($productId);
            if ($shortfall <= 0) {
                continue;
            }
            $product = Product::find($productId);
            $order = $this->roundLot($shortfall, $product->unit_of_measure);
            $pivot = $this->supplierPrice($productId);
            if (! $pivot) {
                continue;
            }
            $bySupplier[$pivot->supplier_id][] = ['product_id' => $productId, 'quantity_ordered' => $this->decimal($order), 'unit_price' => (string) $pivot->price];
        }

        foreach ($bySupplier as $supplierId => $items) {
            try {
                $po = app(CreatePurchaseOrderAction::class)->handle(new CreatePurchaseOrderData(
                    supplierId: $supplierId,
                    orderDate: $day->toDateString(),
                    expectedDeliveryDate: $day->copy()->addDays(2)->toDateString(),
                    createdBy: $this->users['purchasing'],
                    items: $items,
                ));
                app(SubmitPurchaseOrderForApprovalAction::class)->handle($po->fresh());
                app(ApprovePurchaseOrderAction::class)->handle($po->fresh(), $this->users['manager']);
            } catch (Throwable $e) {
                $this->skip('purchase order to supplier '.$supplierId.' on '.$day->toDateString(), $e);
            }
        }
    }

    // --- Wednesday: receive purchases + start molding -------------------------

    protected function receivePurchases(Carbon $day): void
    {
        $orders = PurchaseOrder::query()
            ->whereIn('status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->with(['items', 'supplier'])
            ->get();

        foreach ($orders as $po) {
            try {
                $batch = app(ReceivePurchaseOrderBatchService::class)->handle(new ReceivePurchaseOrderBatchData(
                    purchaseOrderId: $po->id,
                    receivedBy: $this->users['warehouse'][array_rand($this->users['warehouse'])],
                    items: $po->items->map(fn ($item) => [
                        'purchaseOrderItemId' => $item->id,
                        'quantityReceived' => $this->decimal(max(0, (float) $item->quantity_ordered - (float) $item->quantity_received)),
                    ])->all(),
                    attachmentPath: $this->attachmentPath,
                ));
                $supplierId = $po->supplier_id;
                $this->at($day->copy()->addDays(2), fn () => $this->invoicePurchase($batch->id, $supplierId));
            } catch (Throwable $e) {
                $this->skip('receive PO '.$po->id.' on '.$day->toDateString(), $e);
            }
        }
    }

    protected function invoicePurchase(int $batchId, int $supplierId): void
    {
        $batch = PurchaseOrderReceiptBatch::findOrFail($batchId);
        $date = now()->startOfDay();
        $invoice = app(SavePurchaseInvoiceDraftAction::class)->handle($batch, new PurchaseInvoiceData(
            invoiceNumber: sprintf('INV/%d/%s/%04d', $supplierId, $date->format('ym'), $batchId),
            invoiceDate: $date->toDateString(),
            dueDate: $date->copy()->addDays(30)->toDateString(),
            taxRate: '11',
            unitPrices: [],
            capitalizedCharges: '0',
        ), $this->users['accounting']);
        $invoice = app(PostPurchaseInvoiceAction::class)->handle($invoice, $this->users['accounting']);

        $payAfter = mt_rand(0, 10);
        $this->at($date->copy()->addDays(30 + $payAfter), function () use ($invoice) {
            $invoice = PurchaseInvoice::findOrFail($invoice->id);
            app(RecordPurchasePaymentAction::class)->handle(
                $invoice,
                now()->toDateString(),
                bcsub((string) $invoice->total_amount, (string) $invoice->amount_paid, 2),
                PaymentMethod::BankTransfer,
                $this->cashAccountIds[array_rand($this->cashAccountIds)],
                'TRF-'.$invoice->internal_number,
                $this->users['accounting'],
            );
        });
    }

    protected function startMolding(Carbon $day): void
    {
        $wipNeeded = [];
        foreach ($this->fg as $info) {
            [$wipItems] = $this->splitFormulaItems($info['formula']);
            $openQty = $this->openSalesQty($info['id']) + 40;
            foreach ($wipItems as $item) {
                $wipNeeded[$item['product_id']] = ($wipNeeded[$item['product_id']] ?? 0) + $item['qty'] * $openQty;
            }
        }

        foreach ($wipNeeded as $wipProductId => $needed) {
            $wipFormula = ProductionFormula::where('product_id', $wipProductId)->where('is_active', true)->first();
            if (! $wipFormula) {
                continue;
            }
            [, $wipRaw] = $this->splitFormulaItems($wipFormula);

            $target = max(0, $needed - $this->stock($wipProductId));
            foreach ($wipRaw as $item) {
                $availableFor = $this->stock($item['product_id']) / max($item['qty'], 0.0001);
                $target = min($target, $availableFor);
            }
            $target = floor($target / 10) * 10;
            if ($target < 20) {
                continue;
            }

            $this->startWorkOrder($day, $wipFormula, $target, 'molding');
        }
    }

    // --- Thursday: deliveries -------------------------------------------------

    protected function deliver(Carbon $day): void
    {
        $orders = SalesOrder::query()
            ->whereNotIn('status', [SalesOrderStatus::Draft->value, SalesOrderStatus::Cancelled->value])
            ->with('items')
            ->get()
            ->filter(fn (SalesOrder $order) => $order->status->isDeliverable());

        foreach ($orders as $order) {
            $quantities = [];
            foreach ($order->items as $item) {
                $available = floor($this->stock($item->product_id));
                $quantity = min((float) $item->remainingQuantity(), $available);
                if ($quantity >= 1) {
                    $quantities[$item->id] = $this->decimal($quantity);
                }
            }
            if ($quantities === []) {
                continue;
            }

            try {
                $delivery = app(SaveSalesDeliveryDraftAction::class)->handle($order, $quantities, null, $this->users['warehouse'][array_rand($this->users['warehouse'])]);
                $delivery = app(PostSalesDeliveryAction::class)->handle($delivery, $this->users['warehouse'][array_rand($this->users['warehouse'])]);

                $customer = collect($this->customers)->firstWhere('id', $order->customer_id);
                $due = $day->copy()->addDays($order->payment_terms->days());
                $invoice = app(SaveSalesInvoiceDraftAction::class)->handle($delivery, $day->toDateString(), $due->toDateString(), '11', $this->users['accounting']);
                $invoice = app(PostSalesInvoiceAction::class)->handle($invoice, $this->users['accounting']);

                $payOn = $due->copy()->addDays(mt_rand($customer['pay_after'][0], $customer['pay_after'][1]));
                if ($payOn->lt($day)) {
                    $payOn = $day->copy();
                }
                $this->at($payOn, function () use ($invoice, $customer) {
                    $invoice = SalesInvoice::findOrFail($invoice->id);
                    $cash = $customer['terms'] === PaymentTerm::Cash;
                    app(RecordSalesPaymentAction::class)->handle(
                        $invoice,
                        now()->toDateString(),
                        bcsub((string) $invoice->total_amount, (string) $invoice->amount_paid, 2),
                        $cash ? PaymentMethod::Cash : PaymentMethod::BankTransfer,
                        $this->cashAccountIds[array_rand($this->cashAccountIds)],
                        $cash ? null : 'TRF-'.$invoice->invoice_number,
                        $this->users['accounting'],
                    );
                });
            } catch (Throwable $e) {
                $this->skip('deliver SO '.$order->id.' on '.$day->toDateString(), $e);
            }
        }
    }

    // --- Friday: assembly -------------------------------------------------

    protected function startAssembly(Carbon $day): void
    {
        foreach ($this->fg as $code => $info) {
            [$wipItems, $directRaw] = $this->splitFormulaItems($info['formula']);
            $openQty = $this->openSalesQty($info['id']);

            $target = max(0, $openQty - $this->stock($info['id']));
            foreach (array_merge($wipItems, $directRaw) as $item) {
                $availableFor = $this->stock($item['product_id']) / max($item['qty'], 0.0001);
                $target = min($target, $availableFor);
            }
            $target = floor($target / 10) * 10;
            if ($target < 20) {
                continue;
            }

            $this->startWorkOrder($day, $info['formula'], $target, 'assembly');
        }
    }

    protected function startWorkOrder(Carbon $day, ProductionFormula $formula, float $quantity, string $stage): void
    {
        $machineHours = round($stage === 'molding' ? $quantity / 3 : $quantity / 20, 1);
        $laborHours = round($stage === 'molding' ? $machineHours / 4 : $machineHours, 1);
        $crew = $this->crew($stage, $day);

        try {
            $workOrder = app(CreateWorkOrderAction::class)->handle(new CreateWorkOrderData(
                productionFormulaId: $formula->id,
                quantityTarget: $this->decimal($quantity),
                plannedStartDate: $day->toDateString(),
                plannedEndDate: $day->copy()->addDay()->toDateString(),
                createdBy: $this->users['production'],
                labors: array_map(fn (int $employeeId) => ['employeeId' => $employeeId, 'plannedHours' => $this->decimal($laborHours)], $crew),
                plannedMachineHours: $this->decimal($machineHours),
            ));
            $workOrder = app(ReleaseWorkOrderAction::class)->handle($workOrder, $this->users['production']);

            $late = mt_rand(1, 9) === 1;
            $this->openWorkOrders[] = ['work_order' => $workOrder->id, 'post_on' => $day->copy()->addDays($late ? 2 : 1)->toDateString(), 'stage' => $stage];
        } catch (Throwable $e) {
            $this->skip($stage.' work order for formula '.$formula->id.' on '.$day->toDateString(), $e);
        }
    }

    /**
     * @return list<int>
     */
    protected function crew(string $stage, Carbon $day): array
    {
        $ids = Employee::where('status', 'active')->orderBy('id')->get()
            ->filter(fn (Employee $e) => $e->laborRateOn(\Illuminate\Support\Carbon::instance($day)) !== null)
            ->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $half = (int) ceil(count($ids) / 2);
        $pool = $stage === 'molding' ? array_slice($ids, 0, $half) : array_slice($ids, $half);
        $pool = $pool === [] ? $ids : $pool;
        shuffle($pool);

        return array_slice($pool, 0, 2);
    }

    protected function finishWorkOrders(Carbon $day): void
    {
        $remaining = [];
        foreach ($this->openWorkOrders as $open) {
            if ($open['post_on'] !== $day->toDateString()) {
                $remaining[] = $open;

                continue;
            }

            try {
                $workOrder = WorkOrder::with(['labors', 'materials'])->findOrFail($open['work_order']);
                $target = (float) $workOrder->quantity_target;
                $rejectRate = $open['stage'] === 'molding' ? $this->random(0.015, 0.045) : $this->random(0.004, 0.015);
                $reject = round($target * $rejectRate);

                $result = app(SaveProductionResultDraftAction::class)->handle($workOrder, new ProductionResultData(
                    productionDate: $day->toDateString(),
                    quantityGood: $this->decimal($target - $reject),
                    quantityReject: $this->decimal($reject),
                    machineHours: $this->decimal(round((float) $workOrder->planned_machine_hours * $this->random(0.92, 1.06), 1)),
                    materialsUsed: $workOrder->materials->pluck('quantity_planned', 'product_id')->map(fn ($v) => (string) $v)->all(),
                    laborHours: $workOrder->labors->mapWithKeys(fn ($labor) => [$labor->employee_id => $this->decimal(round((float) $labor->planned_hours * $this->random(0.9, 1.07), 1))])->all(),
                    rejectReason: $reject > 0 ? 'Cacat cetak / bocor' : null,
                ), $this->users['production']);
                $result = app(PostProductionResultAction::class)->handle($result, $this->users['production']);

                $receipt = app(SaveProductionReceiptDraftAction::class)->handle($result, (string) $result->quantity_good, null, $this->users['warehouse'][array_rand($this->users['warehouse'])]);
                app(PostProductionReceiptAction::class)->handle($receipt, $this->users['warehouse'][array_rand($this->users['warehouse'])]);
            } catch (Throwable $e) {
                $this->skip('finish work order '.$open['work_order'].' on '.$day->toDateString(), $e);
            }
        }
        $this->openWorkOrders = $remaining;
    }

    // --- monthly: overhead actuals + wage payments (no closing) --------------

    protected function monthlyOverheadAndWages(Carbon $month): void
    {
        $period = new OverheadPeriod($month->format('Y-m'));
        if ($period->isFuture()) {
            return;
        }

        $hours = DB::table('production_results')
            ->join('work_orders', 'work_orders.id', '=', 'production_results.work_order_id')
            ->join('work_centers', 'work_centers.id', '=', 'work_orders.work_center_id')
            ->where('production_results.status', 'posted')
            ->whereBetween('production_results.production_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->groupBy('work_centers.code')
            ->selectRaw('work_centers.code, SUM(production_results.machine_hours) as hours')
            ->pluck('hours', 'code');

        $label = $period->label();
        $expenses = [
            [OverheadCategory::Electricity, 'Tagihan listrik PLN '.$label, ((float) ($hours['MOLDING'] ?? 0) * 7800 + (float) ($hours['ASSEMBLY'] ?? 0) * 15000) * $this->random(0.95, 1.08) + 1500000],
            [OverheadCategory::MachineDepreciation, 'Penyusutan mesin '.$label, 10700000],
            [OverheadCategory::MoldDepreciation, 'Penyusutan molding '.$label, 2800000],
            [OverheadCategory::Maintenance, 'Suku cadang dan servis mesin '.$label, 3000000 * $this->random(0.6, 1.5)],
            [OverheadCategory::Other, 'BOP lain-lain '.$label, 1500000 * $this->random(0.7, 1.3)],
        ];
        foreach ($expenses as [$category, $description, $amount]) {
            try {
                app(RecordOverheadExpenseAction::class)->handle($period, $category, $description, $this->decimal(round($amount, -3)), $this->cashAccountIds[array_rand($this->cashAccountIds)], $this->users['accounting']);
            } catch (Throwable $e) {
                $this->skip('overhead expense '.$category->value.' for '.$label, $e);
            }
        }

        $opEx = Account::where('type', 'expense')->whereNull('system_role')->where('is_active', true)->pluck('id', 'account_name');
        if ($opEx->isNotEmpty()) {
            $accountId = $opEx->values()->first();
            $opExAmount = $this->decimal(round(8000000 * $this->random(0.9, 1.1), -3));
            try {
                app(PostJournalEntryAction::class)->handle(
                    lines: [
                        ['account_id' => $accountId, 'debit' => $opExAmount, 'credit' => '0'],
                        ['account_id' => $this->cashAccountIds[array_rand($this->cashAccountIds)], 'debit' => '0', 'credit' => $opExAmount],
                    ],
                    type: \App\Enums\JournalEntryType::Manual,
                    description: 'Beban operasional '.$label,
                    userId: $this->users['accounting'],
                    entryDate: $period->end->toDateString(),
                );
            } catch (Throwable $e) {
                $this->skip('operating expense journal for '.$label, $e);
            }
        }

        $employees = UnpaidWages::perEmployee($period->end)->map(fn (array $row) => $row['employee']->id)->all();
        if ($employees !== []) {
            try {
                app(PayWagesAction::class)->handle($period->end->toDateString(), now()->toDateString(), $this->cashAccountIds[array_rand($this->cashAccountIds)], $employees, 'Transfer upah '.$label, $this->users['accounting']);
            } catch (Throwable $e) {
                $this->skip('wage payment for '.$label, $e);
            }
        }
    }
}
