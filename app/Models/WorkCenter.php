<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Guarded(['id'])]
class WorkCenter extends Model
{
    use HasFactory;

    public function overheadRates(): HasMany
    {
        return $this->hasMany(OverheadRate::class);
    }

    public function productionFormulas(): HasMany
    {
        return $this->hasMany(ProductionFormula::class);
    }

    /**
     * The overhead rate in effect on $date (today by default) — same lookup as Employee::laborRateOn().
     */
    public function overheadRateOn(?Carbon $date = null): ?OverheadRate
    {
        $day = ($date ?? now())->toDateString();

        return $this->overheadRates()
            ->whereDate('effective_from', '<=', $day)
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByDesc('effective_from')
            ->first();
    }
}
