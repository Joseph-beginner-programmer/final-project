<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class WagePaymentLine extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function wagePayment(): BelongsTo
    {
        return $this->belongsTo(WagePayment::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
