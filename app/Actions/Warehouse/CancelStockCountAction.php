<?php

namespace App\Actions\Warehouse;

use App\Enums\DocumentStatus;
use App\Models\StockCount;
use Illuminate\Support\Facades\DB;

class CancelStockCountAction
{
    public function handle(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count) {
            $count = StockCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            $count->ensureIsDraft();
            $count->transitionTo(DocumentStatus::Cancelled);

            return $count;
        });
    }
}
