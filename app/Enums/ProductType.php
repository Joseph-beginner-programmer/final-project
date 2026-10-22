<?php

namespace App\Enums; 

enum ProductType: string
{
    case RawMaterial = 'raw_material';
    case Wip = 'wip';
    case FinishedGoods = 'finished_goods';
 
    public function isPurchasable(): bool
    {
        return $this === self::RawMaterial;
    }

    public function inventoryRole(): AccountSystemRole
    {
        return match ($this) {
            self::RawMaterial => AccountSystemRole::InventoryRaw,
            self::Wip => AccountSystemRole::InventorySemiFinished,
            self::FinishedGoods => AccountSystemRole::InventoryFinished,
        };
    }

    public function label(): string
    {
        return __(match ($this) {
            self::RawMaterial => 'Raw Material',
            self::Wip => 'Work In Progress',
            self::FinishedGoods => 'Finished Goods',
        });
    }

    public function fixedUnit(): ?string
    {
        return $this === self::RawMaterial ? null : 'Pcs';
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::RawMaterial => 'bg-zinc-100 text-zinc-600 border-zinc-200 dark:bg-white/5 dark:text-zinc-400 dark:border-white/10',
            self::Wip => 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/10 dark:text-blue-400 dark:border-blue-500/20',
            self::FinishedGoods => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
        };
    }
}