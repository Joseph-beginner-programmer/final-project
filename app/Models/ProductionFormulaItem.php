<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Guarded(['id'])]
class ProductionFormulaItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }

    public function productionFormula(): BelongsTo
    {
        return $this->belongsTo(ProductionFormula::class);
    }

    public function materialProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_product_id');
    }
}
