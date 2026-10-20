<?php

namespace App\Exceptions;

use App\Enums\JournalStatus;
use RuntimeException;

class InvalidJournalStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly JournalStatus $from,
        public readonly JournalStatus $to,
        public readonly string $journalNumber = '',
    ) {
        parent::__construct("Journal entry {$journalNumber} cannot transition from [{$from->value}] to [{$to->value}].");
    }

    public function userMessage(): string
    {
        return __('This journal entry has already been reversed.');
    }
}
