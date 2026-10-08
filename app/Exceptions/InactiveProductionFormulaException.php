<?php

namespace App\Exceptions;

use App\Models\ProductionFormula;
use RuntimeException;

class InactiveProductionFormulaException extends RuntimeException
{
    public function __construct(
        public readonly ProductionFormula $formula,
    ) {
        parent::__construct(
            "Production formula #{$formula->id} ({$formula->formula_code}) is inactive and cannot be used for a new work order."
        );
    }

    public function userMessage(): string
    {
        return __('Formula :code is no longer active. Please choose another formula.', ['code' => $this->formula->formula_code]);
    }
}
