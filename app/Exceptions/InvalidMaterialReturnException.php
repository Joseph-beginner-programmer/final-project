<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidMaterialReturnException extends RuntimeException
{
    public const RESULT_NOT_POSTED = 'result_not_posted';
    public const NOTHING_TO_RETURN = 'nothing_to_return';
    public const ALREADY_RETURNED = 'already_returned';
    public const MORE_THAN_ISSUED = 'more_than_issued';

    public function __construct(
        public readonly string $reason,
        public readonly string $detail = '',
    ) {
        parent::__construct("Invalid material return [{$reason}] {$detail}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::RESULT_NOT_POSTED => __('Leftover material can only be returned after the production result is posted.'),
            self::NOTHING_TO_RETURN => __('This work order has no leftover material to return.'),
            self::ALREADY_RETURNED => __('The leftover of this work order already has a material return (:number).', ['number' => $this->detail]),
            self::MORE_THAN_ISSUED => __(':name: more is being returned than was issued to this work order.', ['name' => $this->detail]),
            default => __('Invalid material return.'),
        };
    }
}
