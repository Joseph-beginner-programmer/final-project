<?php

namespace App\Exceptions;

use App\Models\WorkCenter;
use RuntimeException;

class MissingOverheadRateException extends RuntimeException
{
    public function __construct(
        public readonly WorkCenter $workCenter,
        public readonly string $date,
    ) {
        parent::__construct("Work center {$workCenter->code} has no overhead rate in effect on [{$date}].");
    }

    public function userMessage(): string
    {
        return __(':center has no overhead rate set yet. Ask Accounting to set one first.', ['center' => __($this->workCenter->name)]);
    }
}
