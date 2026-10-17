<?php

namespace App\Models;

use App\Enums\OverheadCategory;
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

    protected function casts(): array
    {
        return [
            'uses_molds' => 'boolean',
        ];
    }

    /**
     * @return list<OverheadCategory>
     */
    public function overheadCategories(): array
    {
        return array_values(array_filter(
            OverheadCategory::cases(),
            fn (OverheadCategory $category) => $category !== OverheadCategory::MoldDepreciation || $this->uses_molds,
        ));
    }

    public function overheadRates(): HasMany
    {
        return $this->hasMany(OverheadRate::class);
    }

    public function productionFormulas(): HasMany
    {
        return $this->hasMany(ProductionFormula::class);
    }

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
