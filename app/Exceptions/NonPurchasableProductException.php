<?php

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

class NonPurchasableProductException extends RuntimeException
{
    public function __construct(
        public readonly Product $product,
    ) {
        parent::__construct(
            "Product [{$product->product_code}] is of type [{$product->type->value}] and cannot be purchased."
        );
    }

    public function userMessage(): string
    {
        return __('":name" is not a raw material and cannot be added to a purchase order.', [
            'name' => $this->product->product_name,
        ]);
    }
}
