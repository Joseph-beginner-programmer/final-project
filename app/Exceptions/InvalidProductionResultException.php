<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A production result that breaks a business rule — checked when saving the draft and again on posting.
 */
class InvalidProductionResultException extends RuntimeException
{
    public const NO_GOOD_OUTPUT = 'no_good_output';
    public const DATE_IN_FUTURE = 'date_in_future';
    public const DATE_BEFORE_ISSUE = 'date_before_issue';
    public const USED_MORE_THAN_ISSUED = 'used_more_than_issued';
    public const NO_WORKERS = 'no_workers';
    public const OPEN_ISSUE_DRAFT = 'open_issue_draft';

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
            self::DATE_BEFORE_ISSUE => __('The completion date cannot be before the first materials were issued (:date).', ['date' => $this->detail]),
            self::USED_MORE_THAN_ISSUED => __(':name: material used cannot exceed what the warehouse issued.', ['name' => $this->detail]),
            self::NO_WORKERS => __('Enter the hours of at least one worker.'),
            self::OPEN_ISSUE_DRAFT => __('Material issue :number is still a draft. Ask the warehouse to post or cancel it first.', ['number' => $this->detail]),
            default => __('Invalid production result.'),
        };
    }
}
