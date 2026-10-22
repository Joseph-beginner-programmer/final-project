<?php

namespace App\Actions\Inventory;

use App\DTO\Inventory\CreateStockMovementData;
use App\Enums\Direction;
use App\Enums\StockMovementType;
use App\Models\InventoryLot;
use Illuminate\Support\Collection;

class DrawStockFifoAction
{
    public function __construct(
        private readonly CreateStockMovementAction $createMovement,
    ) {}

    /**
     * @return Collection<int, InventoryLot>
     */
    public function lockAvailableLots(int $productId): Collection
    {
        return InventoryLot::availableFifo($productId)->lockForUpdate()->get();
    }

    /**
     * @return Collection<int, InventoryLot>
     */
    public function availableLots(int $productId): Collection
    {
        return InventoryLot::availableFifo($productId)->get();
    }

    public function available(Collection $lots): string
    {
        return $lots->reduce(fn (string $carry, InventoryLot $lot) => bcadd($carry, (string) $lot->quantity_remaining, 2), '0');
    }

    public function draw(
        Collection $lots,
        int $productId,
        string $quantity,
        StockMovementType $type,
        int $userId,
        string $referenceType,
        int $referenceId,
    ): string {
        $total = '0';

        foreach (self::slices($lots, $quantity) as [$lot, $take, $value]) {
            $lot->quantity_remaining = bcsub((string) $lot->quantity_remaining, $take, 2);
            $lot->value_remaining = bcsub((string) $lot->value_remaining, $value, 2);
            $lot->save();

            $this->createMovement->handle(new CreateStockMovementData(
                productId: $productId,
                inventoryLotId: $lot->id,
                direction: Direction::Out,
                type: $type,
                quantity: $take,
                unitCost: (string) $lot->unit_cost,
                createdBy: $userId,
                referenceId: $referenceId,
                referenceType: $referenceType,
                totalValue: $value,
            ));

            $total = bcadd($total, $value, 2);
        }

        return $total;
    }

    /**
     * @param  Collection<int, InventoryLot>  $lots
     */
    public function estimate(Collection $lots, string $quantity): string
    {
        $total = '0';

        foreach (self::slices($lots, $quantity) as [$lot, $take, $value]) {
            $lot->quantity_remaining = bcsub((string) $lot->quantity_remaining, $take, 2);
            $lot->value_remaining = bcsub((string) $lot->value_remaining, $value, 2);
            $total = bcadd($total, $value, 2);
        }

        return $total;
    }

    /**
     * @param  Collection<int, InventoryLot>  $lots
     * @return list<array{0: InventoryLot, 1: string, 2: string}>
     */
    public static function slices(Collection $lots, string $quantity): array
    {
        $stillNeeded = $quantity;
        $slices = [];

        foreach ($lots as $lot) {
            if (bccomp($stillNeeded, '0', 2) <= 0) {
                break;
            }

            $remaining = (string) $lot->quantity_remaining;
            if (bccomp($remaining, '0', 2) <= 0) {
                continue;
            }

            $take = bccomp($remaining, $stillNeeded, 2) <= 0 ? $remaining : $stillNeeded;
            $value = bccomp($take, $remaining, 2) === 0 ? (string) $lot->value_remaining : bcmul($take, (string) $lot->unit_cost, 2);

            $slices[] = [$lot, $take, $value];
            $stillNeeded = bcsub($stillNeeded, $take, 2);
        }

        return $slices;
    }
}
