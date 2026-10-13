<?php

namespace App\Enums;

enum PaymentTerm: string
{
    case Cash = 'cash';
    case Net7 = 'net_7';
    case Net14 = 'net_14';
    case Net30 = 'net_30';
    case Net60 = 'net_60';

    public function days(): int
    {
        return match ($this) {
            self::Cash => 0,
            self::Net7 => 7,
            self::Net14 => 14,
            self::Net30 => 30,
            self::Net60 => 60,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            default => __('NET :days days', ['days' => $this->days()]),
        };
    }
}
