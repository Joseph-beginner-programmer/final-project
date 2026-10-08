<?php

namespace App\Exceptions;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Shared by every effective-dated rate (labor rates, overhead rates): a new rate must start
 * after the most recent one, so periods never overlap.
 */
class InvalidRateEffectiveDateException extends RuntimeException
{
    public function __construct(
        public readonly string $effectiveFrom,
        public readonly string $latestEffectiveFrom,
        public readonly string $owner = '',
    ) {
        parent::__construct(
            "New rate for {$owner} starts [{$effectiveFrom}], not after the latest rate's start [{$latestEffectiveFrom}]."
        );
    }

    public function userMessage(): string
    {
        return __('The new rate must start after :date, the start date of the most recent rate.', [
            'date' => Carbon::parse($this->latestEffectiveFrom)->format('d M Y'),
        ]);
    }
}
