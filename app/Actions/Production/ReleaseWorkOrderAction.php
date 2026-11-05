<?php

namespace App\Actions\Production;

use App\Actions\Accounting\PostJournalEntryAction;
use App\Actions\Inventory\DrawStockFifoAction;
use App\Enums\AccountSystemRole;
use App\Enums\EmployeeStatus;
use App\Enums\JournalEntryType;
use App\Enums\StockMovementType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InactiveEmployeeException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\MissingLaborRateException;
use App\Exceptions\MissingOverheadRateException;
use App\Exceptions\WorkOrderRequiresLaborException;
use App\Models\WorkOrder;
use App\Support\AccountResolver;
use App\Support\InventoryAccounts;
use Illuminate\Support\Facades\DB;

class ReleaseWorkOrderAction
{
    public function __construct(
        private readonly DrawStockFifoAction $fifo,
    ) {}

    public function handle(WorkOrder $workOrder, int $userId): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $userId) {
            $locked = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            $labors = $locked->labors()->with('employee')->get();

            if ($labors->isEmpty()) {
                throw new WorkOrderRequiresLaborException($locked->id);
            }

            $today = now();

            foreach ($labors as $labor) {
                if ($labor->employee->status !== EmployeeStatus::Active) {
                    throw new InactiveEmployeeException($labor->employee);
                }

                $rate = $labor->employee->laborRateOn($today)
                    ?? throw new MissingLaborRateException($labor->employee, $today->toDateString());

                $labor->hourly_rate = $rate->hourly_rate;
                $labor->planned_cost = bcmul((string) $labor->planned_hours, (string) $rate->hourly_rate, 2);
                $labor->save();
            }

            $overheadRate = $locked->workCenter->overheadRateOn($today)
                ?? throw new MissingOverheadRateException($locked->workCenter, $today->toDateString());

            $locked->overhead_rate = $overheadRate->rate_per_hour;
            $locked->planned_overhead_cost = bcmul((string) $locked->planned_machine_hours, (string) $overheadRate->rate_per_hour, 2);

            $materials = $locked->materials()->with('product')->lockForUpdate()->get();

            $lotsByProduct = [];
            foreach ($materials as $material) {
                $lots = $this->fifo->lockAvailableLots($material->product_id);
                $available = $this->fifo->available($lots);

                if (bccomp($available, (string) $material->quantity_planned, 2) < 0) {
                    throw new InsufficientStockException($material->product, (string) $material->quantity_planned, $available);
                }

                $lotsByProduct[$material->product_id] = $lots;
            }

            foreach ($materials as $material) {
                $material->total_cost = $this->fifo->draw(
                    $lotsByProduct[$material->product_id],
                    $material->product_id,
                    (string) $material->quantity_planned,
                    StockMovementType::ProductionInput,
                    $userId,
                    'work_order_material',
                    $material->id,
                );
                $material->save();
            }

            $locked->released_at = $today;
            $locked->transitionTo(WorkOrderStatus::InProgress);

            app(PostJournalEntryAction::class)->handle(
                lines: array_map(fn (array $line) => [
                    'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], self::journalPlan($materials->map(fn ($material) => ['type' => $material->product->type, 'amount' => (string) $material->total_cost]))),
                type: JournalEntryType::Auto,
                description: __('Materials drawn for :number', ['number' => $locked->wo_number]),
                userId: $userId,
                sourceType: 'work_order',
                sourceId: $locked->id,
            );

            return $locked;
        });
    }

    /**
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public static function journalPlan(iterable $rows): array
    {
        $grouped = InventoryAccounts::group($rows);
        $total = array_reduce($grouped, fn (string $carry, array $row) => bcadd($carry, $row['amount'], 2), '0');

        return [
            ['role' => AccountSystemRole::InventoryWip, 'account_id' => null, 'debit' => $total, 'credit' => '0'],
            ...array_map(fn (array $row) => ['role' => $row['role'], 'account_id' => null, 'debit' => '0', 'credit' => $row['amount']], array_values($grouped)),
        ];
    }
}
