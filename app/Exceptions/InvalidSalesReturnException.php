<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidSalesReturnException extends RuntimeException
{
    public const NOT_DRAFT = 'not_draft';
    public const NO_LINES = 'no_lines';
    public const MORE_THAN_DELIVERED = 'more_than_delivered';
    public const OPEN_DRAFT = 'open_draft';
    public const REASON_REQUIRED = 'reason_required';
    public const FUTURE_DATE = 'future_date';
    public const BEFORE_DELIVERY = 'before_delivery';
    public const NOT_DELIVERED = 'not_delivered';
    public const REFUND_ACCOUNT_REQUIRED = 'refund_account_required';
    public const NOT_CASH_ACCOUNT = 'not_cash_account';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid sales return [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::NOT_DRAFT => __('Only a draft return can be changed.'),
            self::NO_LINES => __('Enter a quantity for at least one item.'),
            self::MORE_THAN_DELIVERED => __(':item: more than was delivered and not yet returned.', ['item' => $this->detail]),
            self::OPEN_DRAFT => __('Return :number for this delivery is still a draft. Open that one instead.', ['number' => $this->detail]),
            self::REASON_REQUIRED => __('Give the reason for the return.'),
            self::FUTURE_DATE => __('The return date cannot be in the future.'),
            self::BEFORE_DELIVERY => __('The return date cannot be before the delivery.'),
            self::NOT_DELIVERED => __('Only a posted delivery can be returned.'),
            self::REFUND_ACCOUNT_REQUIRED => __('The invoice is already paid beyond what is left, so choose the cash or bank account the refund is paid from.'),
            self::NOT_CASH_ACCOUNT => __('Choose an active asset (cash or bank) account for the refund.'),
            default => __('Invalid sales return.'),
        };
    }
}
