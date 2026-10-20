<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidJournalEntryException extends RuntimeException
{
    public const NOT_ENOUGH_LINES = 'not_enough_lines';
    public const LINE_MUST_PICK_ONE_SIDE = 'line_must_pick_one_side';
    public const UNBALANCED = 'unbalanced';
    public const UNMAPPED_ROLE = 'unmapped_role';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid journal entry [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::NOT_ENOUGH_LINES => __('A journal entry needs at least two lines.'),
            self::LINE_MUST_PICK_ONE_SIDE => __('Each line must have either a debit or a credit amount, not both.'),
            self::UNBALANCED => __('Total debit must equal total credit.'),
            self::UNMAPPED_ROLE => __('No account is set for the system role :role yet — open Chart of Accounts and assign one.', ['role' => $this->detail]),
            default => __('Invalid journal entry.'),
        };
    }
}
