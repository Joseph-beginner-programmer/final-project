<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id', 'hourly_rate', 'planned_cost'])]
class WorkOrderLabor extends Model
{
    use HasFactory;

    // table has no created_at/updated_at (matches the ERD)
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'planned_hours' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'planned_cost' => 'decimal:2',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
