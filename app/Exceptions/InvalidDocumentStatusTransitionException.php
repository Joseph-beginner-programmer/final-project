<?php

namespace App\Exceptions;

use App\Enums\DocumentStatus;
use RuntimeException;

class InvalidDocumentStatusTransitionException extends RuntimeException
{
    public function __construct(
        public readonly DocumentStatus $from,
        public readonly DocumentStatus $to,
        public readonly string $document = '',
    ) {
        parent::__construct("{$document} cannot transition from [{$from->value}] to [{$to->value}].");
    }

    public function userMessage(): string
    {
        return __('This document was updated by someone else. Please refresh the page and try again.');
    }
}
