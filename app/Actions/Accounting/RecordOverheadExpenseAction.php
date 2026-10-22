<?php

namespace App\Actions\Accounting;

use App\Enums\AccountSystemRole;
use App\Enums\AccountType;
use App\Enums\JournalEntryType;
use App\Enums\OverheadCategory;
use App\Exceptions\InvalidOverheadEntryException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Support\AccountResolver;
use App\Support\OverheadPeriod;
use Illuminate\Support\Facades\DB;

class RecordOverheadExpenseAction
{
    public function __construct(private PostJournalEntryAction $postJournalEntry) {}

    public function handle(OverheadPeriod $period, OverheadCategory $category, string $description, string $amount, int $creditAccountId, int $userId): JournalEntry
    {
        if ($period->isFuture()) {
            throw new InvalidOverheadEntryException(InvalidOverheadEntryException::FUTURE_PERIOD);
        }
        if (! is_numeric($amount) || bccomp($amount, '0', 2) <= 0) {
            throw new InvalidOverheadEntryException(InvalidOverheadEntryException::INVALID_AMOUNT);
        }

        $creditAccount = Account::find($creditAccountId);
        if (! $creditAccount || ! $creditAccount->is_active
            || ! in_array($creditAccount->type, [AccountType::Asset, AccountType::Liability], true)
            || in_array($creditAccount->system_role, self::reservedRoles(), true)) {
            throw new InvalidOverheadEntryException(InvalidOverheadEntryException::INVALID_CREDIT_ACCOUNT);
        }

        return DB::transaction(function () use ($period, $category, $description, $amount, $creditAccount, $userId) {
            DB::table('accounts')->where('system_role', AccountSystemRole::OverheadApplied->value)->lockForUpdate()->first();

            if ($period->isClosed()) {
                throw new InvalidOverheadEntryException(InvalidOverheadEntryException::PERIOD_CLOSED, $period->label());
            }

            return $this->postJournalEntry->handle(
                lines: array_map(fn (array $line) => [
                    'account_id' => $line['account_id'] ?? AccountResolver::forRole($line['role'])->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], self::plan($category, bcadd($amount, '0', 2), $creditAccount->id)),
                type: JournalEntryType::Manual,
                description: $description,
                userId: $userId,
                sourceType: OverheadPeriod::EXPENSE_SOURCE,
                sourceId: $period->key(),
                entryDate: $period->expenseDate(),
            );
        });
    }

    /**
     * @return list<array{role: ?AccountSystemRole, account_id: ?int, debit: string, credit: string}>
     */
    public static function plan(OverheadCategory $category, string $amount, ?int $creditAccountId): array
    {
        return [
            ['role' => $category->systemRole(), 'account_id' => null, 'debit' => $amount, 'credit' => '0'],
            ['role' => null, 'account_id' => $creditAccountId, 'debit' => '0', 'credit' => $amount],
        ];
    }

    /**
     * @return list<AccountSystemRole>
     */
    public static function reservedRoles(): array
    {
        return [
            AccountSystemRole::InventoryRaw, AccountSystemRole::InventoryWip, AccountSystemRole::InventoryFinished,
            AccountSystemRole::GoodsReceivedNotInvoiced, AccountSystemRole::VatIn, AccountSystemRole::OverheadApplied,
        ];
    }
}
