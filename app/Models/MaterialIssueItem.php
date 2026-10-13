<?php

namespace App\Models;

use App\Enums\Direction;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Guarded(['id', 'total_cost', 'quantity_consumed'])]
class MaterialIssueItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'quantity_consumed' => 'decimal:2',
        ];
    }

    public function materialIssue(): BelongsTo
    {
        return $this->belongsTo(MaterialIssue::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function leftoverValue(string $quantity): string
    {
        return array_reduce($this->reverseDraws($quantity), fn (string $carry, array $part) => bcadd($carry, $part['value'], 2), '0');
    }

    /**
     * @return list<array{movement: StockMovement, quantity: string, value: string}>
     */
    public function reverseDraws(string $quantity): array
    {
        $stillLeft = $quantity;
        $parts = [];

        foreach ($this->stockMovements->where('direction', Direction::Out)->sortByDesc('id') as $movement) {
            if (bccomp($stillLeft, '0', 2) <= 0) {
                break;
            }

            $drawn = (string) $movement->quantity;
            $take = bccomp($drawn, $stillLeft, 2) <= 0 ? $drawn : $stillLeft;

            $parts[] = [
                'movement' => $movement,
                'quantity' => $take,
                'value' => bccomp($take, $drawn, 2) === 0
                    ? (string) $movement->total_value
                    : bcmul($take, (string) $movement->unit_cost, 2),
            ];

            $stillLeft = bcsub($stillLeft, $take, 2);
        }

        return $parts;
    }

    public function unitCost(): string
    {
        return bccomp((string) $this->quantity, '0', 2) > 0
            ? bcdiv((string) $this->total_cost, (string) $this->quantity, 2)
            : '0.00';
    }
}
