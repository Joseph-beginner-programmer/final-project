<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidStockCountException extends RuntimeException
{
    public const ALREADY_OPEN = 'already_open';
    public const NOT_FULLY_COUNTED = 'not_fully_counted';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid stock count [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::ALREADY_OPEN => __('There is already an open stock count (:number). Post or cancel it before starting a new one.', ['number' => $this->detail]),
            self::NOT_FULLY_COUNTED => __('Every product must have a counted quantity before this stock count can be posted.'),
            default => __('Invalid stock count.'),
        };
    }
}
