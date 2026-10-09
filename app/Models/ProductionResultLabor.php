<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id', 'hourly_rate', 'total_cost'])]
class ProductionResultLabor extends Model
{
    use HasFactory;

    // table has no created_at/updated_at (matches the ERD)
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'total_cost' => 'decimal:2',
        ];
    }

    public function productionResult(): BelongsTo
    {
        return $this->belongsTo(ProductionResult::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
