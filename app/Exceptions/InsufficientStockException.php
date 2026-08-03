<?php

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly Product $product,
        public readonly string $requested,
        public readonly string $available,

     ){
        parent::__construct("Product $product->id - $product->product_name does not have sufficient stock (requested $requested, available $available)");
    }

    public function userMessage(): string
    {
        return __('":name" does not have enough stock for this action (requested :requested, available :available).', [
            'name' => $this->product->product_name,
            'requested' => $this->requested,
            'available' => $this->available,
        ]);
    }
        
}