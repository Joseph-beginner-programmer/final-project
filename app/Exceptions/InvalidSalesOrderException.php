<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidSalesOrderException extends RuntimeException
{
    public const NO_LINES = 'no_lines';
    public const NOT_SELLABLE = 'not_sellable';
    public const ALREADY_DELIVERED = 'already_delivered';
    public const DISCOUNT_TOO_HIGH = 'discount_too_high';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid sales order [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::NO_LINES => __('Add at least one product to the order.'),
            self::NOT_SELLABLE => __(':name cannot be sold — only finished goods can.', ['name' => $this->detail]),
            self::DISCOUNT_TOO_HIGH => __('The discount must be between 0 and 100%.'),
            self::ALREADY_DELIVERED => __('Goods have already been delivered for this order, so it can no longer be cancelled.'),
            default => __('Invalid sales order.'),
        };
    }
}
