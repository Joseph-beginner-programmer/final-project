<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidSalesDeliveryException extends RuntimeException
{
    public const ORDER_NOT_DELIVERABLE = 'order_not_deliverable';
    public const NO_LINES = 'no_lines';
    public const MORE_THAN_ORDERED = 'more_than_ordered';
    public const OPEN_DRAFT = 'open_draft';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid sales delivery [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::ORDER_NOT_DELIVERABLE => __('Goods can only be delivered for a confirmed sales order that is not fully delivered yet.'),
            self::NO_LINES => __('Enter a quantity for at least one product.'),
            self::MORE_THAN_ORDERED => __(':name: more cannot be delivered than is still ordered.', ['name' => $this->detail]),
            self::OPEN_DRAFT => __('This order already has a delivery draft (:number). Post or cancel it first.', ['number' => $this->detail]),
            default => __('Invalid sales delivery.'),
        };
    }
}
