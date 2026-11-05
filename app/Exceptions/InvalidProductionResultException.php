<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidProductionResultException extends RuntimeException
{
    public const NO_GOOD_OUTPUT = 'no_good_output';
    public const DATE_IN_FUTURE = 'date_in_future';
    public const DATE_BEFORE_RELEASE = 'date_before_release';
    public const USED_MORE_THAN_PLANNED = 'used_more_than_planned';
    public const USED_PLUS_RETURNED_OVER_PLANNED = 'used_plus_returned_over_planned';
    public const NO_WORKERS = 'no_workers';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid production result [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::NO_GOOD_OUTPUT => __('Enter the good output — a result needs at least one good unit to carry its cost.'),
            self::DATE_IN_FUTURE => __('The completion date cannot be in the future.'),
            self::DATE_BEFORE_RELEASE => __('The completion date cannot be before the work order was released (:date).', ['date' => $this->detail]),
            self::USED_MORE_THAN_PLANNED => __(':name: material used cannot exceed what was planned for this work order.', ['name' => $this->detail]),
            self::USED_PLUS_RETURNED_OVER_PLANNED => __(':name: used plus returned cannot exceed what was planned for this work order.', ['name' => $this->detail]),
            self::NO_WORKERS => __('Enter the hours of at least one worker.'),
            default => __('Invalid production result.'),
        };
    }
}
