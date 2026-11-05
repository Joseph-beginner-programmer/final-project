<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidPpnEntryException extends RuntimeException
{
    public const ALREADY_CLOSED = 'already_closed';
    public const NOT_FINISHED = 'not_finished';
    public const NOTHING_TO_CLOSE = 'nothing_to_close';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid PPN entry [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::ALREADY_CLOSED => __(':period is already closed.', ['period' => $this->detail]),
            self::NOT_FINISHED => __(':period has not ended yet — it can be closed from the first day of the next month.', ['period' => $this->detail]),
            self::NOTHING_TO_CLOSE => __('There is no PPN Masukan or PPN Keluaran in :period, so there is nothing to close.', ['period' => $this->detail]),
            default => __('Invalid PPN entry.'),
        };
    }
}
