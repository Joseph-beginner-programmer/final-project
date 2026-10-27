<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidPurchasePaymentException extends RuntimeException
{
    public const NOT_PAYABLE = 'not_payable';
    public const INVALID_AMOUNT = 'invalid_amount';
    public const EXCEEDS_BALANCE = 'exceeds_balance';
    public const NOT_CASH_ACCOUNT = 'not_cash_account';
    public const BEFORE_INVOICE = 'before_invoice';
    public const FUTURE_DATE = 'future_date';
    public const ALREADY_CANCELLED = 'already_cancelled';
    public const HAS_REFUND = 'has_refund';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid purchase payment [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::NOT_PAYABLE => __('Only a posted, not fully paid invoice can receive a payment.'),
            self::INVALID_AMOUNT => __('The payment amount must be more than zero.'),
            self::EXCEEDS_BALANCE => __('The payment is more than the remaining balance (:amount).', ['amount' => $this->detail]),
            self::NOT_CASH_ACCOUNT => __('Pay from an active asset (cash or bank) account.'),
            self::BEFORE_INVOICE => __('The payment date cannot be before the invoice date.'),
            self::FUTURE_DATE => __('The payment date cannot be in the future.'),
            self::ALREADY_CANCELLED => __('This payment has already been cancelled.'),
            self::HAS_REFUND => __('A return on this invoice already refunded money, so its payments can no longer be cancelled.'),
            default => __('Invalid payment.'),
        };
    }
}
