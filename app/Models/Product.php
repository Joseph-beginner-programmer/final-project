<?php

namespace App\Models;

use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id', 'current_stock'])]
class Product extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'current_stock' => 'decimal:2',
            'safety_stock' => 'decimal:2',
            'reorder_point' => 'decimal:2',
        ];
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class)
            ->using(ProductSupplier::class)
            ->withPivot('price')
            ->withTimestamps();
    }

    // Local Query Scope
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('type', ProductType::RawMaterial->value);
    }

    public function isBelowReorderPoint(): bool
    {
        return bccomp((string) $this->current_stock, (string) $this->reorder_point, 2) <= 0;
    }
}