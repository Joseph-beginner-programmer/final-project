<?php

namespace App\Actions\Accounting;

use App\Actions\Sales\RecordSalesPaymentAction;
use App\Enums\AccountSystemRole;
use App\Enums\AccountType;
use App\Enums\DocumentStatus;
use App\Enums\JournalEntryType;
use App\Exceptions\InvalidWagePaymentException;
use App\Models\Account;
use App\Models\Employee;
use App\Models\WagePayment;
use App\Support\AccountResolver;
use App\Support\UnpaidWages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayWagesAction
{
    public function __construct(private PostJournalEntryAction $postJournalEntry) {}

    /**
     * @param  list<int>  $employeeIds
     */
    public function handle(string $periodEnd, string $paymentDate, int $cashAccountId, array $employeeIds, ?string $reference, int $userId): WagePayment
    {
        if ($employeeIds === []) {
            throw new InvalidWagePaymentException(InvalidWagePaymentException::NO_EMPLOYEES);
        }

        $until = Carbon::parse($periodEnd)->startOfDay();
        $date = Carbon::parse($paymentDate)->startOfDay();
        if ($date->gt(now()->startOfDay())) {
            throw new InvalidWagePaymentException(InvalidWagePaymentException::FUTURE_DATE);
        }
        if ($until->gt($date)) {
            throw new InvalidWagePaymentException(InvalidWagePaymentException::PERIOD_AFTER_PAYMENT);
        }

        $cashAccount = Account::find($cashAccountId);
        if (! $cashAccount || ! $cashAccount->is_active || $cashAccount->type !== AccountType::Asset || in_array($cashAccount->system_role, RecordSalesPaymentAction::reservedRoles(), true)) {
            throw new InvalidWagePaymentException(InvalidWagePaymentException::NOT_CASH_ACCOUNT);
        }

        return DB::transaction(function () use ($until, $date, $cashAccount, $employeeIds, $reference, $userId) {
            $labors = UnpaidWages::query($until)
                ->whereIn('production_result_labors.employee_id', $employeeIds)
                ->lockForUpdate()
                ->get(['production_result_labors.id', 'production_result_labors.employee_id', 'production_result_labors.hours', 'production_result_labors.total_cost']);

            if ($labors->isEmpty()) {
                throw new InvalidWagePaymentException(InvalidWagePaymentException::NOTHING_TO_PAY, Employee::whereIn('id', $employeeIds)->pluck('name')->implode(', '));
            }

            $payment = new WagePayment([
                'payment_date' => $date->toDateString(),
                'period_end' => $until->toDateString(),
                'cash_account_id' => $cashAccount->id,
                'total_amount' => '0',
                'reference' => filled($reference) ? $reference : null,
                'created_by' => $userId,
            ]);
            $payment->status = DocumentStatus::Posted;
            $payment->payment_number = (string) Str::uuid();
            $payment->save();
            $payment->payment_number = sprintf('UPH-%s-%06d', $payment->created_at->format('Y'), $payment->id);

            $total = '0';
            foreach ($labors->groupBy('employee_id') as $employeeId => $rows) {
                $hours = $rows->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->hours, 2), '0');
                $amount = $rows->reduce(fn (string $carry, $row) => bcadd($carry, (string) $row->total_cost, 2), '0');
                $payment->lines()->create(['employee_id' => $employeeId, 'hours' => $hours, 'amount' => $amount]);
                $total = bcadd($total, $amount, 2);
            }

            DB::table('production_result_labors')->whereIn('id', $labors->pluck('id'))->update(['wage_payment_id' => $payment->id]);

            $payment->total_amount = $total;
            $payment->save();

            $this->postJournalEntry->handle(
                lines: array_map(fn (array $line) => [
                    'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], self::plan($total, $cashAccount->id)),
                type: JournalEntryType::Auto,
                description: __('Wage payment :number (work up to :date)', ['number' => $payment->payment_number, 'date' => $until->translatedFormat('d M Y')]),
                userId: $userId,
                sourceType: 'wage_payment',
                sourceId: $payment->id,
                entryDate: $date->toDateString(),
            );

            return $payment;
        });
    }

    /**
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public static function plan(string $amount, ?int $cashAccountId): array
    {
        return [
            ['role' => AccountSystemRole::WagesPayable, 'account_id' => null, 'debit' => $amount, 'credit' => '0'],
            ['role' => null, 'account_id' => $cashAccountId, 'debit' => '0', 'credit' => $amount],
        ];
    }
}
