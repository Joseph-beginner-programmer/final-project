<?php

namespace App\DTO\Inventory;

use App\Enums\Direction;
use App\Enums\StockMovementType;

class CreateStockMovementData {
    public function __construct(
        public readonly int $productId,
        public readonly Direction $direction,
        public readonly StockMovementType $type,
        public readonly string $amount,
        public readonly int $createdBy,
        public readonly ?int $referenceId = null,
        public readonly ?string $referenceType = null,
    ) {}
}