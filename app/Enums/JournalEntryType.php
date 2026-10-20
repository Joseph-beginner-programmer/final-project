<?php

namespace App\Enums;

enum JournalEntryType: string
{
    case Auto = 'auto';
    case Manual = 'manual';
    case Adjustment = 'adjustment';
    case Closing = 'closing';
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Otomatis',
            self::Manual => 'Manual',
            self::Adjustment => 'Penyesuaian',
            self::Closing => 'Penutup',
            self::Opening => 'Saldo Awal',
        };
    }
}
