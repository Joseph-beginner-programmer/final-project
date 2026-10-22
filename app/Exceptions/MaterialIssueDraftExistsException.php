<?php

namespace App\Exceptions;

use App\Models\MaterialIssue;
use RuntimeException;

class MaterialIssueDraftExistsException extends RuntimeException
{
    public function __construct(public readonly MaterialIssue $draft)
    {
        parent::__construct("Work order already has an open material issue draft [{$draft->issue_number}]");
    }

    public function userMessage(): string
    {
        return __('This work order already has an open draft (:number). Continue that draft instead of starting a new one.', ['number' => $this->draft->issue_number]);
    }
}
