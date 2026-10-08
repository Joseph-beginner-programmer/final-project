<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A draft line that breaks a business rule: no lines at all, a non-issuable product
 * (finished goods), or a material outside the WO plan without a note explaining why.
 */
class InvalidMaterialIssueLineException extends RuntimeException
{
    public const NO_LINES = 'no_lines';
    public const NOT_ISSUABLE = 'not_issuable';
    public const NOTE_REQUIRED = 'note_required';

    public function __construct(
        public readonly string $reason,
        public readonly string $productName = '',
    ) {
        parent::__construct("Invalid material issue line [{$reason}] {$productName}");
    }

    public function userMessage(): string
    {
        return match ($this->reason) {
            self::NO_LINES => __('Enter a quantity for at least one material.'),
            self::NOT_ISSUABLE => __(':name cannot be issued to production (only raw materials and WIP).', ['name' => $this->productName]),
            self::NOTE_REQUIRED => __(':name is not in the work order plan — add a note explaining why it is needed.', ['name' => $this->productName]),
            default => __('Invalid material issue line.'),
        };
    }
}
