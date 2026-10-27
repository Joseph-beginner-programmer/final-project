<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidPurchaseInvoiceException extends RuntimeException
{
    public const NOT_DRAFT = 'not_draft';
    public const ALREADY_INVOICED = 'already_invoiced';
    public const DUE_BEFORE_INVOICE = 'due_before_invoice';
    public const FUTURE_DATE = 'future_date';
    public const INVALID_LINE = 'invalid_line';
    public const NOT_EXPENSE_ACCOUNT = 'not_expense_account';
    public const HAS_PAYMENTS = 'has_payments';
    public const NOT_POSTED = 'not_posted';
    public const HAS_RETURNS = 'has_returns';
    public const EMPTY_RECEIPT = 'empty_receipt';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid purchase invoice [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::NOT_DRAFT => __(':number is no longer a draft and cannot be changed.', ['number' => $this->detail]),
            self::ALREADY_INVOICED => __('This receipt already has a purchase invoice (:number).', ['number' => $this->detail]),
            self::DUE_BEFORE_INVOICE => __('The due date cannot be before the invoice date.'),
            self::FUTURE_DATE => __('The invoice date cannot be in the future.'),
            self::INVALID_LINE => __('Every line needs a price of zero or more.'),
            self::NOT_EXPENSE_ACCOUNT => __(':name is not an expense account.', ['name' => $this->detail]),
            self::HAS_PAYMENTS => __('This invoice already has payments. Cancel the payments first.'),
            self::NOT_POSTED => __('Only a posted invoice can do this.'),
            self::HAS_RETURNS => __('Goods on this invoice were already returned. A posted return cannot be undone, so the invoice cannot be cancelled.'),
            self::EMPTY_RECEIPT => __('This receipt has no received items to invoice.'),
            default => __('Invalid purchase invoice.'),
        };
    }
}
