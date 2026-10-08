<?php

namespace App\DTO\Production;

class CreateWorkOrderData
{
    /**
     * @param array<int, array{employeeId: int, plannedHours: string}> $labors
     */
    public function __construct(
        public readonly int $productionFormulaId,
        public readonly string $quantityTarget,
        public readonly string $plannedStartDate,
        public readonly string $plannedEndDate,
        public readonly int $createdBy,
        public readonly array $labors = [],
        public readonly string $plannedMachineHours = '0',
    ) {}
}
