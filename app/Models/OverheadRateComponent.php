<?php

namespace App\Models;

use App\Enums\OverheadCategory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class OverheadRateComponent extends Model
{
    protected function casts(): array
    {
        return [
            'category' => OverheadCategory::class,
            'amount' => 'decimal:2',
        ];
    }

    public function overheadRate(): BelongsTo
    {
        return $this->belongsTo(OverheadRate::class);
    }
}
