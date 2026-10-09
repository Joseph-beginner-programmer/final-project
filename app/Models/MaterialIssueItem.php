<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Guarded(['id', 'total_cost', 'quantity_consumed'])]
class MaterialIssueItem extends Model
{
    use HasFactory;

    // table has no created_at/updated_at (matches the ERD)
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

    /**
     * The FIFO OUT movements this line produced on posting — one per lot drawn from.
     */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    /**
     * Value of $quantity of this line that was NOT used, priced at the lots drawn LAST (reverse FIFO),
     * so a leftover is the exact undo of the last draws: stock ends up as if only the used amount had
     * been issued. The same walk decides which lots a Material Return refills (decided 2026-10-08).
     * Expects stockMovements to be loaded or loadable; the line must be posted.
     */
    public function leftoverValue(string $quantity): string
    {
        $stillLeft = $quantity;
        $value = '0';

        foreach ($this->stockMovements->sortByDesc('id') as $movement) {
            if (bccomp($stillLeft, '0', 2) <= 0) {
                break;
            }

            $drawn = (string) $movement->quantity;
            $take = bccomp($drawn, $stillLeft, 2) <= 0 ? $drawn : $stillLeft;

            // a whole draw comes back at exactly what it took out (incl. the emptying draw's rounding)
            $value = bcadd($value, bccomp($take, $drawn, 2) === 0
                ? (string) $movement->total_value
                : bcmul($take, (string) $movement->unit_cost, 2), 2);

            $stillLeft = bcsub($stillLeft, $take, 2);
        }

        return $value;
    }

    /**
     * Average FIFO cost per unit of this line (a line can span lots at different costs).
     */
    public function unitCost(): string
    {
        return bccomp((string) $this->quantity, '0', 2) > 0
            ? bcdiv((string) $this->total_cost, (string) $this->quantity, 2)
            : '0.00';
    }
}
