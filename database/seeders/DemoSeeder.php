<?php

namespace Database\Seeders;

use App\Actions\Accounting\CloseOverheadPeriodAction;
use App\Actions\Accounting\PayWagesAction;
use App\Actions\Accounting\PostJournalEntryAction;
use App\Actions\Accounting\RecordOverheadExpenseAction;
use App\Actions\Employee\CreateEmployeeAction;
use App\Actions\Employee\SetLaborRateAction;
use App\Actions\MasterData\SaveAccountAction;
use App\Actions\Production\CreateWorkOrderAction;
use App\Actions\Production\PostMaterialIssueAction;
use App\Actions\Production\PostProductionResultAction;
use App\Actions\Production\ReleaseWorkOrderAction;
use App\Actions\Production\SaveMaterialIssueDraftAction;
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
use App\Actions\Sales\SaveCustomerAction;
use App\Actions\Sales\SaveSalesInvoiceDraftAction;
use App\Actions\Sales\SaveSalesOrderDraftAction;
use App\Actions\Warehouse\PostProductionReceiptAction;
use App\Actions\Warehouse\PostSalesDeliveryAction;
use App\Actions\Warehouse\SaveProductionReceiptDraftAction;
use App\Actions\Warehouse\SaveSalesDeliveryDraftAction;
use App\DTO\Employee\EmployeeData;
use App\DTO\Production\CreateWorkOrderData;
use App\DTO\Production\ProductionResultData;
use App\DTO\Purchasing\CreatePurchaseOrderData;
use App\DTO\Purchasing\PurchaseInvoiceData;
use App\DTO\Purchasing\ReceivePurchaseOrderBatchData;
use App\DTO\Sales\SalesOrderData;
use App\Enums\AccountSystemRole;
use App\Enums\AccountType;
use App\Enums\JournalEntryType;
use App\Enums\OverheadCategory;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTerm;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\UserRole;
use App\Enums\WorkOrderStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductionFormula;
use App\Models\ProductionResult;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceiptBatch;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\WorkCenter;
use App\Models\WorkOrder;
use App\Services\Purchasing\ReceivePurchaseOrderBatchService;
use App\Support\OverheadPeriod;
use App\Support\UnpaidWages;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class DemoSeeder extends Seeder
{
    public const START = '2026-05-01';

    protected const ATTACHMENT = 'purchase-order-receipts/demo-surat-jalan.pdf';

    /**
     * @var array<string, int>
     */
    protected array $users = [];

    /**
     * @var array<string, int>
     */
    protected array $accounts = [];

    /**
     * @var array<string, list<callable>>
     */
    protected array $events = [];

    /**
     * @var array<string, int>
     */
    protected array $fgPlan = [];

    /**
     * @var list<array{work_order: int, post_on: string, stage: string}>
     */
    protected array $openWorkOrders = [];

    protected Carbon $today;

    /**
     * @var array<string, array{badan: string, color: string, mix: float, price: string}>
     */
    protected array $lines = [
        'FG-001' => ['badan' => 'WIP-001', 'color' => 'RAW-001', 'mix' => 0.45, 'price' => '48000'],
        'FG-002' => ['badan' => 'WIP-003', 'color' => 'RAW-002', 'mix' => 0.35, 'price' => '46000'],
        'FG-003' => ['badan' => 'WIP-004', 'color' => 'RAW-003', 'mix' => 0.20, 'price' => '42000'],
    ];

    /**
     * @var list<array{name: string, weight: float, terms: PaymentTerm, discount: string, charge: string, pay_after: array{0: int, 1: int}}>
     */
    protected array $customers = [
        ['name' => 'Toko Sumber Rejeki', 'weight' => 0.30, 'terms' => PaymentTerm::Net30, 'discount' => '0', 'charge' => '0', 'pay_after' => [-2, 3]],
        ['name' => 'CV Maju Jaya Abadi', 'weight' => 0.25, 'terms' => PaymentTerm::Net30, 'discount' => '2', 'charge' => '0', 'pay_after' => [0, 8]],
        ['name' => 'PT Grosir Nusantara', 'weight' => 0.20, 'terms' => PaymentTerm::Net14, 'discount' => '3', 'charge' => '0', 'pay_after' => [12, 30]],
        ['name' => 'UD Sinar Terang', 'weight' => 0.15, 'terms' => PaymentTerm::Net30, 'discount' => '0', 'charge' => '150000', 'pay_after' => [45, 70]],
        ['name' => 'Toko Berkah Jaya', 'weight' => 0.10, 'terms' => PaymentTerm::Cash, 'discount' => '0', 'charge' => '100000', 'pay_after' => [0, 0]],
    ];

    /**
     * @var array<string, int>
     */
    protected array $supplierPayAfter = ['SUP-001' => 0, 'SUP-002' => 3, 'SUP-003' => 9];

    public function run(): void
    {
        mt_srand(2026);
        $this->today = now()->startOfDay();

        $this->travel(Carbon::parse(self::START)->subDay());
        Model::unguarded(fn () => $this->seedMasterData());
        $this->seedAccounts();
        $this->postOpeningBalances();

        for ($day = Carbon::parse(self::START); $day->lte($this->today); $day->addDay()) {
            $this->travel($day->copy());
            $this->runDay($day->copy());
        }

        $this->leaveOpenWork();
        Date::setTestNow();
    }

    protected function travel(Carbon $day): void
    {
        Date::setTestNow($day->copy()->setTime(9, 0));
    }

    protected function seedMasterData(): void
    {
        $people = [
            UserRole::Manager->value => ['Hendra Wijaya', 'manager@test.com'],
            UserRole::Purchasing->value => ['Lina Purchasing', 'purchasing@test.com'],
            UserRole::Sales->value => ['Andi Sales', 'sales@test.com'],
            UserRole::Accounting->value => ['Rina Akuntansi', 'accounting@test.com'],
            UserRole::Production->value => ['Yusuf Produksi', 'production@test.com'],
            UserRole::Warehouse->value => ['Doni Gudang', 'warehouse@test.com'],
        ];
        foreach ($people as $role => [$name, $email]) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make('password'), 'role' => $role]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->users[$role] = $user->id;
        }

        $this->call([SupplierSeeder::class, ProductSeeder::class, ProductSupplierSeeder::class, ProductionSeeder::class, EmployeeSeeder::class]);

        foreach ([['Joko Susilo', '24000'], ['Wahyuni', '23000'], ['Bambang Pratama', '25000'], ['Sri Mulyani', '22000'], ['Eko Saputra', '24000']] as [$name, $rate]) {
            $employee = app(CreateEmployeeAction::class)->handle(new EmployeeData($name, '2024-06-01'));
            app(SetLaborRateAction::class)->handle($employee, $rate, '2026-01-01', $this->users[UserRole::Manager->value]);
        }

        Product::where('product_code', 'WIP-001')->update(['product_name' => 'Badan Termos Merah']);
        Product::where('product_code', 'FG-001')->update(['selling_price' => $this->lines['FG-001']['price']]);
        foreach ([
            'WIP-003' => ['product_name' => 'Badan Termos Biru', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'WIP-004' => ['product_name' => 'Badan Termos Hijau', 'unit_of_measure' => 'Pcs', 'type' => 'wip'],
            'FG-002' => ['product_name' => 'Termos 1L Biru', 'unit_of_measure' => 'Pcs', 'type' => 'finished_goods', 'selling_price' => $this->lines['FG-002']['price']],
            'FG-003' => ['product_name' => 'Termos 1L Hijau', 'unit_of_measure' => 'Pcs', 'type' => 'finished_goods', 'selling_price' => $this->lines['FG-003']['price']],
        ] as $code => $attributes) {
            Product::firstOrCreate(['product_code' => $code], $attributes);
        }

        $molding = WorkCenter::where('code', 'MOLDING')->value('id');
        $assembly = WorkCenter::where('code', 'ASSEMBLY')->value('id');
        foreach ([
            'F-WIP-003' => ['WIP-003', $molding, ['RAW-002' => '13.60', 'RAW-004' => '0.50']],
            'F-WIP-004' => ['WIP-004', $molding, ['RAW-003' => '13.60', 'RAW-004' => '0.50']],
            'F-FG-002' => ['FG-002', $assembly, ['WIP-003' => '100', 'WIP-002' => '100']],
            'F-FG-003' => ['FG-003', $assembly, ['WIP-004' => '100', 'WIP-002' => '100']],
        ] as $code => [$productCode, $workCenterId, $items]) {
            $formula = ProductionFormula::firstOrCreate(['formula_code' => $code], [
                'product_id' => $this->productId($productCode),
                'work_center_id' => $workCenterId,
                'output_quantity' => '100',
                'is_active' => true,
                'created_by' => $this->users[UserRole::Production->value],
            ]);
            foreach ($items as $materialCode => $quantity) {
                $formula->items()->firstOrCreate(['material_product_id' => $this->productId($materialCode)], ['quantity' => $quantity]);
            }
        }

        $addresses = ['Jl. Pasar Atom 12, Surabaya', 'Jl. Raya Darmo 88, Surabaya', 'Jl. Gatot Subroto 5, Sidoarjo', 'Jl. Panglima Sudirman 40, Gresik', 'Jl. Kenjeran 101, Surabaya'];
        foreach ($this->customers as $i => $customer) {
            $model = app(SaveCustomerAction::class)->handle([
                'customer_name' => $customer['name'],
                'contact_person' => 'Bpk. '.explode(' ', $customer['name'])[1],
                'phone' => '+62812300'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'email' => null,
                'address' => $addresses[$i],
            ]);
            $this->customers[$i]['id'] = $model->id;
        }

        Storage::disk('local')->put(self::ATTACHMENT, "%PDF-1.4\n% surat jalan demo\n");
    }

    protected function seedAccounts(): void
    {
        $accounts = [
            ['1000', 'Kas', AccountType::Asset, null, false],
            ['1001', 'Piutang Usaha', AccountType::Asset, AccountSystemRole::AccountsReceivable, false],
            ['1002', 'Persediaan Bahan Baku', AccountType::Asset, AccountSystemRole::InventoryRaw, false],
            ['1003', 'Persediaan Barang Dalam Proses', AccountType::Asset, AccountSystemRole::InventoryWip, false],
            ['1004', 'Persediaan Barang Jadi', AccountType::Asset, AccountSystemRole::InventoryFinished, false],
            ['1005', 'PPN Masukan', AccountType::Asset, AccountSystemRole::VatIn, false],
            ['1006', 'Persediaan Barang Setengah Jadi', AccountType::Asset, AccountSystemRole::InventorySemiFinished, false],
            ['1010', 'Bank BCA', AccountType::Asset, null, false],
            ['1200', 'Mesin dan Peralatan', AccountType::Asset, null, false],
            ['1201', 'Akumulasi Penyusutan Mesin', AccountType::Asset, null, true],
            ['1210', 'Cetakan (Molding)', AccountType::Asset, null, false],
            ['1211', 'Akumulasi Penyusutan Molding', AccountType::Asset, null, true],
            ['2000', 'Utang Usaha', AccountType::Liability, AccountSystemRole::AccountsPayable, false],
            ['2001', 'Barang Diterima Belum Difakturkan', AccountType::Liability, AccountSystemRole::GoodsReceivedNotInvoiced, false],
            ['2002', 'Utang Gaji', AccountType::Liability, AccountSystemRole::WagesPayable, false],
            ['2003', 'PPN Keluaran', AccountType::Liability, AccountSystemRole::VatOut, false],
            ['3000', 'Modal Usaha', AccountType::Equity, null, false],
            ['4000', 'Penjualan', AccountType::Revenue, AccountSystemRole::SalesRevenue, false],
            ['4001', 'Retur Penjualan', AccountType::Revenue, AccountSystemRole::SalesReturns, true],
            ['5000', 'Harga Pokok Penjualan', AccountType::Expense, AccountSystemRole::Cogs, false],
            ['5001', 'BOP Dibebankan', AccountType::Expense, AccountSystemRole::OverheadApplied, true],
            ['5002', 'Selisih Bahan', AccountType::Expense, AccountSystemRole::MaterialVariance, false],
            ['5003', 'Kerugian Produksi', AccountType::Expense, AccountSystemRole::ProductionLoss, false],
            ['5004', 'Selisih Harga Pembelian', AccountType::Expense, AccountSystemRole::PurchasePriceVariance, false],
            ['5010', 'BOP Listrik', AccountType::Expense, AccountSystemRole::OverheadElectricity, false],
            ['5011', 'BOP Penyusutan Mesin', AccountType::Expense, AccountSystemRole::OverheadMachineDepreciation, false],
            ['5012', 'BOP Penyusutan Molding', AccountType::Expense, AccountSystemRole::OverheadMoldDepreciation, false],
            ['5013', 'BOP Pemeliharaan', AccountType::Expense, AccountSystemRole::OverheadMaintenance, false],
            ['5014', 'BOP Lain-lain', AccountType::Expense, AccountSystemRole::OverheadOther, false],
            ['6000', 'Beban Gaji Kantor', AccountType::Expense, null, false],
            ['6001', 'Beban Administrasi dan Umum', AccountType::Expense, null, false],
            ['6002', 'Beban Pemasaran', AccountType::Expense, null, false],
        ];

        foreach ($accounts as [$code, $name, $type, $role, $contra]) {
            $account = app(SaveAccountAction::class)->handle([
                'account_code' => $code,
                'account_name' => $name,
                'type' => $type,
                'system_role' => $role,
                'is_active' => true,
                'is_contra' => $contra,
            ]);
            $this->accounts[$code] = $account->id;
        }
    }

    protected function postOpeningBalances(): void
    {
        $this->travel(Carbon::parse(self::START));
        $this->journal(JournalEntryType::Opening, 'Saldo awal perusahaan', [
            ['1000', '25000000', '0'],
            ['1010', '250000000', '0'],
            ['1200', '600000000', '0'],
            ['1210', '150000000', '0'],
            ['3000', '0', '1025000000'],
        ]);
    }

    protected function runDay(Carbon $day): void
    {
        foreach ($this->events[$day->toDateString()] ?? [] as $event) {
            $event();
        }

        if ($day->day === 1 && $day->gt(Carbon::parse(self::START))) {
            $this->closeMonth($day->copy()->subMonthNoOverflow());
        }

        if ($day->toDateString() === '2026-08-01') {
            DB::table('product_supplier')->where('product_id', $this->productId('RAW-001'))->update(['price' => 33000]);
        }

        $this->finishWorkOrders($day);

        match ($day->dayOfWeekIso) {
            1 => [$this->purchase($day), $this->takeOrders($day)],
            3 => [$this->receivePurchases(), $this->startStuckWorkOrder($day), $this->startMolding($day)],
            4 => $this->deliver($day),
            5 => $this->startAssembly($day),
            default => null,
        };
    }

    protected function at(Carbon $day, callable $event): void
    {
        if ($day->lte(now()->startOfDay())) {
            $event();
        } elseif ($day->lte($this->today)) {
            $this->events[$day->toDateString()][] = $event;
        }
    }

    protected function weeklyDemand(Carbon $day): float
    {
        $monthIndex = ($day->year - 2026) * 12 + $day->month - 5;

        return (1500 + max(0, $monthIndex) * 220) * 12 / 52;
    }

    protected function purchase(Carbon $day): void
    {
        $badan = [];
        foreach ($this->lines as $fg => $line) {
            $badan[$line['color']] = $this->weeklyDemand($day->copy()->addDays(9)) * $line['mix'] * 1.1;
        }
        $totalBadan = array_sum($badan);

        $needs = $badan;
        foreach ($needs as $code => $quantity) {
            $needs[$code] = $quantity * 0.136;
        }
        $needs['RAW-004'] = $totalBadan * 0.005;
        $needs['RAW-005'] = $totalBadan * 0.04;
        if ($day->lt(Carbon::parse(self::START)->addDays(7))) {
            $needs['RAW-006'] = 120;
        }

        $bySupplier = [];
        foreach ($needs as $code => $kg) {
            $order = ceil(max(0, $kg * 1.3 - $this->stock($code) - $this->onOrder($code)) / 25) * 25;
            if ($order <= 0) {
                continue;
            }
            $productId = $this->productId($code);
            $pivot = DB::table('product_supplier')->where('product_id', $productId)->first();
            $bySupplier[$pivot->supplier_id][] = ['product_id' => $productId, 'quantity_ordered' => $this->decimal($order), 'unit_price' => (string) $pivot->price];
        }

        foreach ($bySupplier as $supplierId => $items) {
            $po = app(CreatePurchaseOrderAction::class)->handle(new CreatePurchaseOrderData(
                supplierId: $supplierId,
                orderDate: $day->toDateString(),
                expectedDeliveryDate: $day->copy()->addDays(2)->toDateString(),
                createdBy: $this->users[UserRole::Purchasing->value],
                items: $items,
            ));
            app(SubmitPurchaseOrderForApprovalAction::class)->handle($po->fresh());
            app(ApprovePurchaseOrderAction::class)->handle($po->fresh(), $this->users[UserRole::Manager->value]);
        }
    }

    protected function receivePurchases(): void
    {
        $orders = PurchaseOrder::query()
            ->whereIn('status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->with(['items', 'supplier'])
            ->get();

        foreach ($orders as $po) {
            $batch = app(ReceivePurchaseOrderBatchService::class)->handle(new ReceivePurchaseOrderBatchData(
                purchaseOrderId: $po->id,
                receivedBy: $this->users[UserRole::Warehouse->value],
                items: $po->items->map(fn ($item) => [
                    'purchaseOrderItemId' => $item->id,
                    'quantityReceived' => $this->decimal(max(0, (float) $item->quantity_ordered - (float) $item->quantity_received)),
                ])->all(),
                attachmentPath: self::ATTACHMENT,
            ));

            $supplierCode = $po->supplier->supplier_code;
            $this->at(now()->addDays(2)->startOfDay(), fn () => $this->invoicePurchase($batch->id, $supplierCode));
        }
    }

    protected function invoicePurchase(int $batchId, string $supplierCode): void
    {
        $batch = PurchaseOrderReceiptBatch::findOrFail($batchId);
        $date = now()->startOfDay();
        $invoice = app(SavePurchaseInvoiceDraftAction::class)->handle($batch, new PurchaseInvoiceData(
            invoiceNumber: sprintf('INV/%s/%s/%04d', substr($supplierCode, -1), $date->format('ym'), $batchId),
            invoiceDate: $date->toDateString(),
            dueDate: $date->copy()->addDays(30)->toDateString(),
            taxRate: '11',
            unitPrices: [],
            capitalizedCharges: '0',
        ), $this->users[UserRole::Accounting->value]);
        $invoice = app(PostPurchaseInvoiceAction::class)->handle($invoice, $this->users[UserRole::Accounting->value]);

        $this->at($date->copy()->addDays(30 + $this->supplierPayAfter[$supplierCode]), function () use ($invoice) {
            $invoice = PurchaseInvoice::findOrFail($invoice->id);
            app(RecordPurchasePaymentAction::class)->handle(
                $invoice,
                now()->toDateString(),
                bcsub((string) $invoice->total_amount, (string) $invoice->amount_paid, 2),
                PaymentMethod::BankTransfer,
                $this->accounts['1010'],
                'TRF-'.$invoice->internal_number,
                $this->users[UserRole::Accounting->value],
            );
        });
    }

    protected function takeOrders(Carbon $day): void
    {
        $weekly = $this->weeklyDemand($day);

        foreach ($this->customers as $customer) {
            $lines = [];
            foreach ($this->lines as $fg => $line) {
                $quantity = round($weekly * $line['mix'] * $customer['weight'] * $this->random(0.8, 1.2) / 10) * 10;
                if ($quantity >= 10) {
                    $lines[] = ['productId' => $this->productId($fg), 'quantity' => $this->decimal($quantity), 'unitPrice' => $line['price']];
                }
            }
            if ($lines === []) {
                continue;
            }

            $order = app(SaveSalesOrderDraftAction::class)->handle(new SalesOrderData(
                customerId: $customer['id'],
                salespersonId: $this->users[UserRole::Sales->value],
                orderDate: $day->toDateString(),
                expectedDeliveryDate: $day->copy()->addDays(3)->toDateString(),
                paymentTerms: $customer['terms'],
                lines: $lines,
                discountPercent: $customer['discount'],
                charges: $customer['charge'] !== '0' ? [['description' => 'Ongkos kirim', 'amount' => $customer['charge']]] : [],
            ), $this->users[UserRole::Sales->value]);
            app(ConfirmSalesOrderAction::class)->handle($order, $this->users[UserRole::Sales->value]);
        }
    }

    protected function deliver(Carbon $day): void
    {
        $orders = SalesOrder::query()
            ->whereNotIn('status', [SalesOrderStatus::Draft->value, SalesOrderStatus::Cancelled->value])
            ->with('items')
            ->orderBy('id')
            ->get()
            ->filter(fn (SalesOrder $order) => $order->status->isDeliverable());

        foreach ($orders as $order) {
            $quantities = [];
            foreach ($order->items as $item) {
                $available = floor((float) Product::whereKey($item->product_id)->value('current_stock'));
                $quantity = min((float) $item->remainingQuantity(), $available);
                if ($quantity >= 1) {
                    $quantities[$item->id] = $this->decimal($quantity);
                }
            }
            if ($quantities === []) {
                continue;
            }

            $delivery = app(SaveSalesDeliveryDraftAction::class)->handle($order, $quantities, null, $this->users[UserRole::Warehouse->value]);
            $delivery = app(PostSalesDeliveryAction::class)->handle($delivery, $this->users[UserRole::Warehouse->value]);

            $customer = collect($this->customers)->firstWhere('id', $order->customer_id);
            $due = $day->copy()->addDays($order->payment_terms->days());
            $invoice = app(SaveSalesInvoiceDraftAction::class)->handle($delivery, $day->toDateString(), $due->toDateString(), '11', $this->users[UserRole::Accounting->value]);
            $invoice = app(PostSalesInvoiceAction::class)->handle($invoice, $this->users[UserRole::Accounting->value]);

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
                    $cash ? $this->accounts['1000'] : $this->accounts['1010'],
                    $cash ? null : 'TRF-'.$invoice->invoice_number,
                    $this->users[UserRole::Accounting->value],
                );
            });
        }
    }

    protected function startMolding(Carbon $day): void
    {
        $nextWeek = $this->weeklyDemand($day->copy()->addDays(8));
        $open = $this->openSalesQuantities();

        $this->fgPlan = [];
        foreach ($this->lines as $fg => $line) {
            $plan = $nextWeek * $line['mix'] + ($open[$this->productId($fg)] ?? 0) - $this->stock($fg);
            $this->fgPlan[$fg] = (int) max(0, ceil($plan / 50) * 50);
        }

        $putihLeft = $this->stock('RAW-004');
        $tutupNeeded = 0;
        foreach ($this->lines as $fg => $line) {
            $need = ceil(max(0, $this->fgPlan[$fg] * 1.04 - $this->stock($line['badan'])) / 10) * 10;
            $byRaw = floor(min($this->stock($line['color']) / 0.136, $putihLeft / 0.005) / 10) * 10;
            $quantity = min($need, $byRaw);
            if ($quantity >= 50) {
                $putihLeft -= $quantity * 0.005;
                $this->startWorkOrder($day, $line['badan'], $quantity, 'molding');
            }
            $tutupNeeded += $this->fgPlan[$fg] * 1.04;
        }

        $tutup = ceil(max(0, $tutupNeeded - $this->stock('WIP-002')) / 10) * 10;
        $tutup = min($tutup, floor($this->stock('RAW-005') / 0.04 / 10) * 10);
        if ($tutup >= 50) {
            $this->startWorkOrder($day, 'WIP-002', $tutup, 'molding');
        }
    }

    protected function startStuckWorkOrder(Carbon $day): void
    {
        $lastWednesday = $this->today->copy()->subDays(4);
        while ($lastWednesday->dayOfWeekIso !== 3) {
            $lastWednesday->subDay();
        }

        if ($day->isSameDay($lastWednesday) && $this->stock('RAW-005') >= 12) {
            $this->startWorkOrder($day, 'WIP-002', 300, 'molding', 'never');
        }
    }

    protected function startAssembly(Carbon $day): void
    {
        $tutupLeft = $this->stock('WIP-002');
        foreach ($this->lines as $fg => $line) {
            $quantity = floor(min($this->fgPlan[$fg] ?? 0, $this->stock($line['badan']), $tutupLeft) / 10) * 10;
            if ($quantity >= 20) {
                $tutupLeft -= $quantity;
                $this->startWorkOrder($day, $fg, $quantity, 'assembly');
            }
        }
    }

    protected function startWorkOrder(Carbon $day, string $productCode, float $quantity, string $stage, ?string $postOn = null): WorkOrder
    {
        $formula = ProductionFormula::where('product_id', $this->productId($productCode))->firstOrFail();
        $machineHours = round($stage === 'molding' ? $quantity / 3 : $quantity / 20, 1);
        $crew = $this->crew($stage);
        $laborHours = round($stage === 'molding' ? $machineHours / 4 : $machineHours, 1);

        $workOrder = app(CreateWorkOrderAction::class)->handle(new CreateWorkOrderData(
            productionFormulaId: $formula->id,
            quantityTarget: $this->decimal($quantity),
            plannedStartDate: $day->toDateString(),
            plannedEndDate: $day->copy()->addDay()->toDateString(),
            createdBy: $this->users[UserRole::Production->value],
            labors: array_map(fn (int $employeeId) => ['employeeId' => $employeeId, 'plannedHours' => $this->decimal($laborHours)], $crew),
            plannedMachineHours: $this->decimal($machineHours),
        ));
        $workOrder = app(ReleaseWorkOrderAction::class)->handle($workOrder);

        $lines = $workOrder->materials()->get()->map(fn ($material) => ['productId' => $material->product_id, 'quantity' => (string) $material->quantity_planned, 'note' => null])->all();
        $issue = app(SaveMaterialIssueDraftAction::class)->handle($workOrder, $lines, $this->users[UserRole::Warehouse->value]);
        app(PostMaterialIssueAction::class)->handle($issue, $this->users[UserRole::Warehouse->value]);

        $late = mt_rand(1, 9) === 1;
        $this->openWorkOrders[] = ['work_order' => $workOrder->id, 'post_on' => $postOn ?? $day->copy()->addDays($late ? 2 : 1)->toDateString(), 'stage' => $stage];

        return $workOrder;
    }

    /**
     * @return list<int>
     */
    protected function crew(string $stage): array
    {
        $ids = Employee::orderBy('id')->pluck('id')->all();
        $pool = $stage === 'molding' ? array_slice($ids, 0, 6) : array_slice($ids, 6);
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

            $workOrder = WorkOrder::with(['labors', 'materials'])->findOrFail($open['work_order']);
            $target = (float) $workOrder->quantity_target;
            $rejectRate = $open['stage'] === 'molding' ? $this->random(0.015, 0.045) : $this->random(0.004, 0.015);
            $reject = round($target * $rejectRate);

            $result = app(SaveProductionResultDraftAction::class)->handle($workOrder, new ProductionResultData(
                productionDate: $day->toDateString(),
                quantityGood: $this->decimal($target - $reject),
                quantityReject: $this->decimal($reject),
                machineHours: $this->decimal(round((float) $workOrder->planned_machine_hours * $this->random(0.92, 1.06), 1)),
                materialsUsed: $workOrder->issuedQuantities(),
                laborHours: $workOrder->labors->mapWithKeys(fn ($labor) => [$labor->employee_id => $this->decimal(round((float) $labor->planned_hours * $this->random(0.9, 1.07), 1))])->all(),
                rejectReason: $reject > 0 ? 'Cacat cetak / bocor' : null,
            ), $this->users[UserRole::Production->value]);
            $result = app(PostProductionResultAction::class)->handle($result, $this->users[UserRole::Production->value]);

            $receipt = app(SaveProductionReceiptDraftAction::class)->handle($result, (string) $result->quantity_good, null, $this->users[UserRole::Warehouse->value]);
            app(PostProductionReceiptAction::class)->handle($receipt, $this->users[UserRole::Warehouse->value]);
        }
        $this->openWorkOrders = $remaining;
    }

    protected function closeMonth(Carbon $month): void
    {
        $period = new OverheadPeriod($month->format('Y-m'));
        $hours = ProductionResult::query()
            ->join('work_orders', 'work_orders.id', '=', 'production_results.work_order_id')
            ->join('work_centers', 'work_centers.id', '=', 'work_orders.work_center_id')
            ->where('production_results.status', 'posted')
            ->whereBetween('production_results.production_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->groupBy('work_centers.code')
            ->selectRaw('work_centers.code, SUM(production_results.machine_hours) as hours')
            ->pluck('hours', 'code');

        $accounting = $this->users[UserRole::Accounting->value];
        $label = $period->label();
        $expenses = [
            [OverheadCategory::Electricity, 'Tagihan listrik PLN '.$label, ((float) ($hours['MOLDING'] ?? 0) * 7800 + (float) ($hours['ASSEMBLY'] ?? 0) * 15000) * $this->random(0.95, 1.08) + 1500000, '1010'],
            [OverheadCategory::MachineDepreciation, 'Penyusutan mesin '.$label, 10700000, '1201'],
            [OverheadCategory::MoldDepreciation, 'Penyusutan molding '.$label, 2800000, '1211'],
            [OverheadCategory::Maintenance, 'Suku cadang dan servis mesin '.$label, 3000000 * $this->random(0.6, 1.5), '1010'],
            [OverheadCategory::Other, 'BOP lain-lain '.$label, 1500000 * $this->random(0.7, 1.3), '1000'],
        ];
        foreach ($expenses as [$category, $description, $amount, $credit]) {
            app(RecordOverheadExpenseAction::class)->handle($period, $category, $description, $this->decimal(round($amount, -3)), $this->accounts[$credit], $accounting);
        }
        app(CloseOverheadPeriodAction::class)->handle($period, $accounting);

        $this->journal(JournalEntryType::Manual, 'Beban operasional '.$label, [
            ['6000', '8000000', '0'],
            ['6001', $this->decimal(round(2000000 * $this->random(0.85, 1.2), -3)), '0'],
            ['6002', $this->decimal(round(1800000 * $this->random(0.6, 1.6), -3)), '0'],
            ['1010', '0', null],
        ], $period->end->toDateString());

        $employees = UnpaidWages::perEmployee($period->end)->map(fn (array $row) => $row['employee']->id)->all();
        if ($employees !== []) {
            app(PayWagesAction::class)->handle($period->end->toDateString(), now()->toDateString(), $this->accounts['1010'], $employees, 'Transfer upah '.$label, $accounting);
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: ?string}>  $lines
     */
    protected function journal(JournalEntryType $type, string $description, array $lines, ?string $date = null): void
    {
        $debits = array_reduce($lines, fn (string $carry, array $line) => bcadd($carry, $line[1], 2), '0');
        app(PostJournalEntryAction::class)->handle(
            lines: array_map(fn (array $line) => [
                'account_id' => $this->accounts[$line[0]],
                'debit' => $line[1],
                'credit' => $line[2] ?? $debits,
            ], $lines),
            type: $type,
            description: $description,
            userId: $this->users[UserRole::Accounting->value],
            entryDate: $date,
        );
    }

    protected function leaveOpenWork(): void
    {
        $this->travel($this->today);
        $purchasing = $this->users[UserRole::Purchasing->value];

        foreach ([['RAW-001', '400'], ['RAW-006', '150']] as [$code, $quantity]) {
            $pivot = DB::table('product_supplier')->where('product_id', $this->productId($code))->first();
            $po = app(CreatePurchaseOrderAction::class)->handle(new CreatePurchaseOrderData(
                supplierId: $pivot->supplier_id,
                orderDate: $this->today->toDateString(),
                expectedDeliveryDate: $this->today->copy()->addDays(4)->toDateString(),
                createdBy: $purchasing,
                items: [['product_id' => $this->productId($code), 'quantity_ordered' => $quantity, 'unit_price' => (string) $pivot->price]],
            ));
            app(SubmitPurchaseOrderForApprovalAction::class)->handle($po->fresh());
        }

        $formula = ProductionFormula::where('product_id', $this->productId('WIP-001'))->firstOrFail();
        app(CreateWorkOrderAction::class)->handle(new CreateWorkOrderData(
            productionFormulaId: $formula->id,
            quantityTarget: '6000',
            plannedStartDate: $this->today->copy()->addDays(3)->toDateString(),
            plannedEndDate: $this->today->copy()->addDays(5)->toDateString(),
            createdBy: $this->users[UserRole::Production->value],
            labors: array_map(fn (int $id) => ['employeeId' => $id, 'plannedHours' => '250'], $this->crew('molding')),
            plannedMachineHours: '2000',
        ));
    }

    /**
     * @return array<int, float>
     */
    protected function openSalesQuantities(): array
    {
        return DB::table('sales_order_items')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->whereNotIn('sales_orders.status', [SalesOrderStatus::Draft->value, SalesOrderStatus::Cancelled->value])
            ->groupBy('sales_order_items.product_id')
            ->selectRaw('sales_order_items.product_id, SUM(GREATEST(sales_order_items.quantity_ordered - sales_order_items.quantity_delivered, 0)) as open')
            ->pluck('open', 'product_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    protected function onOrder(string $code): float
    {
        return (float) DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_order_items.product_id', $this->productId($code))
            ->whereIn('purchase_orders.status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->sum(DB::raw('purchase_order_items.quantity_ordered - purchase_order_items.quantity_received'));
    }

    protected function stock(string $code): float
    {
        return (float) Product::where('product_code', $code)->value('current_stock');
    }

    protected function productId(string $code): int
    {
        static $ids = [];

        return $ids[$code] ??= Product::where('product_code', $code)->value('id');
    }

    protected function random(float $min, float $max): float
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }

    protected function decimal(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
