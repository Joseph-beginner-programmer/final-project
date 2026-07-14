<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Table('product_supplier')]
class ProductSupplier extends Pivot
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }
}