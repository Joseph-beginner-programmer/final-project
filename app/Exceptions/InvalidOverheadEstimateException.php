<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidOverheadEstimateException extends RuntimeException
{
    public const NEGATIVE_AMOUNT = 'negative_amount';
    public const NO_COSTS = 'no_costs';
    public const NO_HOURS = 'no_hours';
    public const CATEGORY_NOT_USED = 'category_not_used';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid overhead estimate [{$reason}]");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::NEGATIVE_AMOUNT => __('Overhead amounts cannot be negative.'),
            self::NO_COSTS => __('Enter at least one overhead cost.'),
            self::NO_HOURS => __('Budgeted machine hours must be more than 0.'),
            self::CATEGORY_NOT_USED => __(':category does not apply to this work center.', ['category' => $this->detail]),
            default => __('Invalid data.'),
        };
    }
}
