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

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => 'Raw Material',
            self::Wip => 'Work In Progress',
            self::FinishedGoods => 'Finished Goods',
        };
    }
}