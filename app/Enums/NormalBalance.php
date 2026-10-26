<?php

namespace App\Enums;

enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit',
            self::Credit => 'Kredit',
        };
    }
}
