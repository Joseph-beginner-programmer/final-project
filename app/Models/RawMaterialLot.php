<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class RawMaterialLot extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'remaining_stock' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseOrderReceipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderReceipt::class);
    }
}
