<?php

namespace App\DTO\Inventory;

use App\Enums\Direction;
use App\Enums\StockMovementType;

/**
 * One stock movement = one lot. A FIFO issue that spans several lots is several of these.
 */
class CreateStockMovementData {
    public function __construct(
        public readonly int $productId,
        public readonly int $inventoryLotId,
        public readonly Direction $direction,
        public readonly StockMovementType $type,
        public readonly string $quantity,
        public readonly string $unitCost,
        public readonly int $createdBy,
        public readonly ?int $referenceId = null,
        public readonly ?string $referenceType = null,
        // exact value when it isn't quantity × unit cost: the draw that empties a lot takes the lot's
        // whole remaining value, so rounding never leaves stray rupiah in an empty lot
        public readonly ?string $totalValue = null,
    ) {}
}
