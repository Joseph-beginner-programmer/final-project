<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class Supplier extends Model
{
    use HasFactory;

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    } 

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->using(ProductSupplier::class)
            ->withPivot('price')
            ->withTimestamps();
    }
}