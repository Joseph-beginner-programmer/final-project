<?php

namespace App\Exceptions;

use App\Models\MaterialIssue;
use RuntimeException;

class MaterialIssueNotEditableException extends RuntimeException
{
    public function __construct(
        public readonly MaterialIssue $issue,
    ) {
        parent::__construct("Material issue {$issue->issue_number} is {$issue->status->value}, not draft.");
    }

    public function userMessage(): string
    {
        return __('Only a draft material issue can be changed.');
    }
}
