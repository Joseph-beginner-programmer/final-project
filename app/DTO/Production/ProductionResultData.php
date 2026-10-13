<?php

namespace App\DTO\Production;

class ProductionResultData
{
    /**
     * @param array<int, string> $materialsUsed product id => quantity used
     * @param array<int, string> $laborHours    employee id => actual hours
     */
    public function __construct(
        public readonly string $productionDate,
        public readonly string $quantityGood,
        public readonly string $quantityReject,
        public readonly string $machineHours,
        public readonly array $materialsUsed,
        public readonly array $laborHours,
        public readonly ?string $rejectReason = null,
        public readonly ?string $notes = null,
    ) {}
}
