<?php

namespace App\Actions\Production;

use App\Enums\DocumentStatus;
use App\Models\MaterialIssue;
use Illuminate\Support\Facades\DB;

/**
 * Only a draft can be cancelled — nothing has left the warehouse yet, so there is nothing to reverse.
 */
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
