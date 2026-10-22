<?php

namespace App\Actions\Production;

use App\Actions\Accounting\PostJournalEntryAction;
use App\DTO\Production\ProductionResultData;
use App\Enums\AccountSystemRole;
use App\Enums\DocumentStatus;
use App\Enums\JournalEntryType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidProductionResultException;
use App\Exceptions\MissingLaborRateException;
use App\Exceptions\WorkOrderNotRecordableException;
use App\Models\Employee;
use App\Models\ProductionResult;
use App\Models\WorkOrder;
use App\Support\AccountResolver;
use Illuminate\Support\Facades\DB;

class PostProductionResultAction
{
    public function __construct(
        private readonly ValidateProductionResultAction $validate,
    ) {}

    public function handle(ProductionResult $result, int $userId): ProductionResult
    {
        return DB::transaction(function () use ($result, $userId) {
            $result = ProductionResult::whereKey($result->id)->lockForUpdate()->firstOrFail();
            $result->ensureIsDraft();
            $result->load(['materials', 'labors']);

            $workOrder = WorkOrder::whereKey($result->work_order_id)->lockForUpdate()->firstOrFail();
            if (! $workOrder->canRecordResult()) {
                throw new WorkOrderNotRecordableException($workOrder, WorkOrderNotRecordableException::NOT_IN_PROGRESS);
            }

            $openIssue = $workOrder->materialIssues()->where('status', DocumentStatus::Draft->value)->first();
            if ($openIssue) {
                throw new InvalidProductionResultException(InvalidProductionResultException::OPEN_ISSUE_DRAFT, $openIssue->issue_number);
            }

            $result->production_date = today();

            $data = new ProductionResultData(
                productionDate: $result->production_date->toDateString(),
                quantityGood: (string) $result->quantity_good,
                quantityReject: (string) $result->quantity_reject,
                machineHours: (string) $result->machine_hours,
                materialsUsed: $result->usedQuantities(),
                laborHours: $result->labors->mapWithKeys(fn ($labor) => [$labor->employee_id => (string) $labor->hours])->all(),
            );
            $this->validate->handle($workOrder, $data);

            $issueLines = $workOrder->postedIssueItems(lock: true);
            $result->materials()->delete();
            $materialCost = '0';

            foreach (SaveProductionResultDraftAction::allocate($issueLines, $data->materialsUsed) as [$item, $used]) {
                $leftover = bcsub((string) $item->quantity, $used, 2);
                $cost = bcsub((string) $item->total_cost, $item->leftoverValue($leftover), 2);

                $row = $result->materials()->make([
                    'material_issue_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => $used,
                ]);
                $row->unit_cost = bcdiv($cost, $used, 2);
                $row->total_cost = $cost;
                $row->save();

                $item->quantity_consumed = $used;
                $item->save();

                $materialCost = bcadd($materialCost, $cost, 2);
            }

            $plannedRates = $workOrder->labors()->pluck('hourly_rate', 'employee_id');
            $laborCost = '0';

            foreach ($result->labors as $labor) {
                $rate = self::laborRate($plannedRates, $labor->employee_id, $result->production_date);

                $labor->hourly_rate = (string) $rate;
                $labor->total_cost = bcmul((string) $labor->hours, (string) $rate, 2);
                $labor->save();

                $laborCost = bcadd($laborCost, (string) $labor->total_cost, 2);
            }

            $overheadCost = bcmul((string) $result->machine_hours, (string) $workOrder->overhead_rate, 2);

            $total = bcadd(bcadd($materialCost, $laborCost, 2), $overheadCost, 2);

            $result->material_cost = $materialCost;
            $result->labor_cost = $laborCost;
            $result->overhead_cost = $overheadCost;
            $result->total_cost = $total;
            $result->unit_cost = bcdiv($total, (string) $result->quantity_good, 2);
            $result->posted_by = $userId;
            $result->posted_at = now();
            $result->transitionTo(DocumentStatus::Posted);

            $workOrder->quantity_good = $result->quantity_good;
            $workOrder->quantity_reject = $result->quantity_reject;
            $workOrder->transitionTo(WorkOrderStatus::Completed);

            app(PostJournalEntryAction::class)->handle(
                lines: array_map(fn (array $line) => [
                    'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], self::journalPlan($laborCost, $overheadCost)),
                type: JournalEntryType::Auto,
                description: __('Production result :number', ['number' => $result->result_number]),
                userId: $userId,
                sourceType: 'production_result',
                sourceId: $result->id,
            );

            return $result->load(['materials', 'labors']);
        });
    }

    /**
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public function preview(ProductionResult $result): array
    {
        $result->loadMissing('labors');
        $workOrder = $result->workOrder;
        $plannedRates = $workOrder->labors()->pluck('hourly_rate', 'employee_id');
        $laborCost = $result->labors->reduce(
            fn (string $carry, $labor) => bcadd($carry, bcmul((string) $labor->hours, (string) self::laborRate($plannedRates, $labor->employee_id, today()), 2), 2),
            '0',
        );

        return self::journalPlan($laborCost, bcmul((string) $result->machine_hours, (string) $workOrder->overhead_rate, 2));
    }

    /**
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public static function journalPlan(string $laborCost, string $overheadCost): array
    {
        return [
            ['role' => AccountSystemRole::InventoryWip, 'account_id' => null, 'debit' => bcadd($laborCost, $overheadCost, 2), 'credit' => '0'],
            ['role' => AccountSystemRole::WagesPayable, 'account_id' => null, 'debit' => '0', 'credit' => $laborCost],
            ['role' => AccountSystemRole::OverheadApplied, 'account_id' => null, 'debit' => '0', 'credit' => $overheadCost],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $plannedRates
     */
    public static function laborRate(\Illuminate\Support\Collection $plannedRates, int $employeeId, \Carbon\CarbonInterface $date): string
    {
        $rate = $plannedRates[$employeeId] ?? null;
        if ($rate === null) {
            $employee = Employee::findOrFail($employeeId);
            $rate = $employee->laborRateOn(\Illuminate\Support\Carbon::instance($date))?->hourly_rate
                ?? throw new MissingLaborRateException($employee, $date->toDateString());
        }

        return (string) $rate;
    }
}
