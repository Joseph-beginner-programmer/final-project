<?php

namespace App\Exceptions;

use App\Models\MaterialReturn;
use RuntimeException;

class MaterialReturnNotEditableException extends RuntimeException
{
    public function __construct(
        public readonly MaterialReturn $materialReturn,
    ) {
        parent::__construct("Material return {$materialReturn->return_number} is {$materialReturn->status->value}, not draft.");
    }

    public function userMessage(): string
    {
        return __('Only a draft material return can be changed.');
    }
}
