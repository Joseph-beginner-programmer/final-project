<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidWagePaymentException extends RuntimeException
{
    public const NO_EMPLOYEES = 'no_employees';
    public const NOTHING_TO_PAY = 'nothing_to_pay';
    public const FUTURE_DATE = 'future_date';
    public const PERIOD_AFTER_PAYMENT = 'period_after_payment';
    public const NOT_CASH_ACCOUNT = 'not_cash_account';
    public const ALREADY_CANCELLED = 'already_cancelled';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid wage payment [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::NO_EMPLOYEES => __('Choose at least one employee to pay.'),
            self::NOTHING_TO_PAY => __('There are no unpaid wages for :employees up to this date.', ['employees' => $this->detail]),
            self::FUTURE_DATE => __('The payment date cannot be in the future.'),
            self::PERIOD_AFTER_PAYMENT => __('Wages can only be paid for work done on or before the payment date.'),
            self::NOT_CASH_ACCOUNT => __('Pay from an active asset (cash or bank) account.'),
            self::ALREADY_CANCELLED => __('This wage payment has already been cancelled.'),
            default => __('Invalid wage payment.'),
        };
    }
}
