<?php

namespace App\Actions\Production;

use App\Enums\DocumentStatus;
use App\Models\MaterialIssue;
use Illuminate\Support\Facades\DB;

class CancelMaterialIssueAction
{
    public function handle(MaterialIssue $issue): MaterialIssue
    {
        return DB::transaction(function () use ($issue) {
            $issue = MaterialIssue::whereKey($issue->id)->lockForUpdate()->firstOrFail();
            $issue->ensureIsDraft();
            $issue->transitionTo(DocumentStatus::Cancelled);

            return $issue;
        });
    }
}
