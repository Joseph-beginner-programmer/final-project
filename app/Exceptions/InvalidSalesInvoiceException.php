<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidSalesInvoiceException extends RuntimeException
{
    public const NOT_DRAFT = 'not_draft';
    public const ALREADY_INVOICED = 'already_invoiced';
    public const DUE_BEFORE_INVOICE = 'due_before_invoice';
    public const FUTURE_DATE = 'future_date';
    public const BEFORE_DELIVERY = 'before_delivery';
    public const NOT_DELIVERED = 'not_delivered';
    public const HAS_PAYMENTS = 'has_payments';
    public const NOT_POSTED = 'not_posted';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid sales invoice [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::NOT_DRAFT => __(':number is no longer a draft and cannot be changed.', ['number' => $this->detail]),
            self::ALREADY_INVOICED => __('This delivery already has a sales invoice (:number).', ['number' => $this->detail]),
            self::DUE_BEFORE_INVOICE => __('The due date cannot be before the invoice date.'),
            self::FUTURE_DATE => __('The invoice date cannot be in the future.'),
            self::BEFORE_DELIVERY => __('The invoice date cannot be before the delivery date (:date).', ['date' => $this->detail]),
            self::NOT_DELIVERED => __('Only a posted delivery can be invoiced.'),
            self::HAS_PAYMENTS => __('This invoice already has payments. Cancel the payments first.'),
            self::NOT_POSTED => __('Only a posted invoice can do this.'),
            default => __('Invalid sales invoice.'),
        };
    }
}
