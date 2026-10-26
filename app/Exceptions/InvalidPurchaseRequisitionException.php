<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidPurchaseRequisitionException extends RuntimeException
{
    public const NOT_OPEN = 'not_open';
    public const NO_LINES = 'no_lines';
    public const NOT_PURCHASABLE = 'not_purchasable';
    public const NEEDED_BY_PAST = 'needed_by_past';
    public const REASON_REQUIRED = 'reason_required';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid purchase requisition [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::NOT_OPEN => __(':number is no longer open (already processed, rejected or cancelled).', ['number' => $this->detail]),
            self::NO_LINES => __('Enter a quantity for at least one material.'),
            self::NOT_PURCHASABLE => __(':name is not a purchasable raw material.', ['name' => $this->detail]),
            self::NEEDED_BY_PAST => __('The needed-by date cannot be in the past.'),
            self::REASON_REQUIRED => __('Give a reason for declining the request.'),
            default => __('Invalid purchase request.'),
        };
    }
}
