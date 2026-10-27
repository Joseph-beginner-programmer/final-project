<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Guarded(['id', 'cogs_total'])]
class SalesDeliveryItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'cogs_total' => 'decimal:2',
        ];
    }

    public function salesDelivery(): BelongsTo
    {
        return $this->belongsTo(SalesDelivery::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function returnedQuantity(?int $exceptReturnId = null): string
    {
        return bcadd((string) $this->returnItems()
            ->whereHas('salesReturn', fn ($q) => $q->where('status', DocumentStatus::Posted->value))
            ->when($exceptReturnId, fn ($q) => $q->where('sales_return_id', '!=', $exceptReturnId))
            ->sum('quantity'), '0', 2);
    }

    public function netQuantity(): string
    {
        return bcsub((string) $this->quantity, $this->returnedQuantity(), 2);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
