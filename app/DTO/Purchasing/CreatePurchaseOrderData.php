<?php

namespace App\DTO\Purchasing;

class CreatePurchaseOrderData
{
    /**
     * @param array<int, array{product_id: int, quantity_ordered: string, unit_price: string}> $items
     */
    public function __construct(
        public readonly int $supplierId,
        public readonly string $orderDate,
        public readonly ?string $expectedDeliveryDate,
        public readonly int $createdBy,
        public readonly array $items = [], 
    ) {}
}
