<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidPurchaseReturnException extends RuntimeException
{
    public const NOT_DRAFT = 'not_draft';
    public const NO_LINES = 'no_lines';
    public const MORE_THAN_RETURNABLE = 'more_than_returnable';
    public const OPEN_DRAFT = 'open_draft';
    public const REASON_REQUIRED = 'reason_required';
    public const FUTURE_DATE = 'future_date';
    public const BEFORE_RECEIPT = 'before_receipt';
    public const DRAFT_INVOICE = 'draft_invoice';
    public const REFUND_ACCOUNT_REQUIRED = 'refund_account_required';
    public const NOT_CASH_ACCOUNT = 'not_cash_account';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid purchase return [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::NOT_DRAFT => __('Only a draft return can be changed.'),
            self::NO_LINES => __('Enter a quantity for at least one item.'),
            self::MORE_THAN_RETURNABLE => __(':item: more than can still be returned (received and still in stock).', ['item' => $this->detail]),
            self::OPEN_DRAFT => __('Return :number for this receipt is still a draft. Open that one instead.', ['number' => $this->detail]),
            self::REASON_REQUIRED => __('Give the reason for the return.'),
            self::FUTURE_DATE => __('The return date cannot be in the future.'),
            self::BEFORE_RECEIPT => __('The return date cannot be before the goods were received.'),
            self::DRAFT_INVOICE => __('Purchase invoice :number for this receipt is still a draft. Post or cancel it before posting the return.', ['number' => $this->detail]),
            self::REFUND_ACCOUNT_REQUIRED => __('The invoice is already paid beyond what is left, so choose the cash or bank account the supplier refunds into.'),
            self::NOT_CASH_ACCOUNT => __('Choose an active asset (cash or bank) account for the refund.'),
            default => __('Invalid purchase return.'),
        };
    }
}
