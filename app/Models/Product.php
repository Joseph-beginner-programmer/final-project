<?php

namespace App\Models;

use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id', 'product_code', 'current_stock'])]
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
            'selling_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function inventoryLots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }

    public function productionFormulas(): HasMany
    {
        return $this->hasMany(ProductionFormula::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class)
            ->using(ProductSupplier::class)
            ->withPivot('price')
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isLocked(): bool
    {
        return bccomp((string) $this->current_stock, '0', 2) !== 0
            || $this->stockMovements()->exists()
            || $this->purchaseOrderItems()->exists()
            || $this->workOrders()->exists()
            || ProductionFormulaItem::where('material_product_id', $this->id)->exists()
            || SalesOrderItem::where('product_id', $this->id)->exists();
    }

    public static function codePrefix(ProductType $type): string
    {
        return match ($type) {
            ProductType::RawMaterial => 'RAW',
            ProductType::Wip => 'WIP',
            ProductType::FinishedGoods => 'FG',
        };
    }

    /**
     * @return array{id: int, title: string, subtitle: string, meta: ?string, disabled_reason: ?string, badge: array{label: string, class: string}}
     */
    public function toPickerOption(?string $meta = null, ?string $disabledReason = null): array
    {
        return [
            'id' => $this->id,
            'title' => $this->product_name,
            'subtitle' => $this->product_code,
            'meta' => $meta,
            'disabled_reason' => $disabledReason,
            'badge' => ['label' => $this->type->label(), 'class' => $this->type->badgeClasses()],
        ];
    }

    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('type', ProductType::RawMaterial->value);
    }

    public function isBelowReorderPoint(): bool
    {
        return bccomp((string) $this->current_stock, (string) $this->reorder_point, 2) <= 0;
    }
}