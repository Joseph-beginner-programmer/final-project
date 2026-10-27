<?php

namespace App\Enums;

enum ReturnCondition: string
{
    case Good = 'good';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::Good => __('Resellable'),
            self::Damaged => __('Damaged'),
        };
    }

    public function restocks(): bool
    {
        return $this === self::Good;
    }
}
