<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidOverheadEntryException extends RuntimeException
{
    public const PERIOD_CLOSED = 'period_closed';
    public const ALREADY_CLOSED = 'already_closed';
    public const FUTURE_PERIOD = 'future_period';
    public const INVALID_AMOUNT = 'invalid_amount';
    public const INVALID_CREDIT_ACCOUNT = 'invalid_credit_account';
    public const NOT_AN_EXPENSE = 'not_an_expense';
    public const ALREADY_CANCELLED = 'already_cancelled';
    public const NOT_FINISHED = 'not_finished';
    public const NOTHING_TO_CLOSE = 'nothing_to_close';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid overhead entry [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::PERIOD_CLOSED => __(':period is already closed. Book the bill in the next open month.', ['period' => $this->detail]),
            self::ALREADY_CLOSED => __(':period is already closed.', ['period' => $this->detail]),
            self::FUTURE_PERIOD => __('Overhead cannot be recorded for a future month.'),
            self::INVALID_AMOUNT => __('The amount must be more than zero.'),
            self::INVALID_CREDIT_ACCOUNT => __('Choose an active asset or liability account to credit (cash, bank, payable or accumulated depreciation).'),
            self::NOT_AN_EXPENSE => __('This journal entry is not an overhead expense.'),
            self::ALREADY_CANCELLED => __('This overhead entry has already been cancelled.'),
            self::NOT_FINISHED => __(':period has not ended yet — it can be closed from the first day of the next month.', ['period' => $this->detail]),
            self::NOTHING_TO_CLOSE => __('There is no applied or actual overhead in :period, so there is nothing to close.', ['period' => $this->detail]),
            default => __('Invalid overhead entry.'),
        };
    }
}
