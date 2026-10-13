<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidProductionReceiptException extends RuntimeException
{
    public const RESULT_NOT_POSTED = 'result_not_posted';
    public const ALREADY_RECEIVED = 'already_received';
    public const QUANTITY_REQUIRED = 'quantity_required';
    public const MORE_THAN_PRODUCED = 'more_than_produced';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid production receipt [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::RESULT_NOT_POSTED => __('The output can only be received after the production result is posted.'),
            self::ALREADY_RECEIVED => __('This production result already has a receipt (:number).', ['number' => $this->detail]),
            self::QUANTITY_REQUIRED => __('Enter the quantity received — more than 0.'),
            self::MORE_THAN_PRODUCED => __('More cannot be received than production reported as good (:quantity).', ['quantity' => $this->detail]),
            default => __('Invalid production receipt.'),
        };
    }
}
