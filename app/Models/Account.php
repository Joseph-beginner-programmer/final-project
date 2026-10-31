<?php

namespace App\Models;

use App\Enums\AccountSystemRole;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['id', 'normal_balance'])]
class Account extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'system_role' => AccountSystemRole::class,
            'is_active' => 'boolean',
            'is_contra' => 'boolean',
            'is_cash' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeCash(Builder $query): Builder
    {
        return $query->where('is_cash', true);
    }
}
