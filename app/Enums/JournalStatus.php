<?php

namespace App\Enums;

enum JournalStatus: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Terposting',
            self::Reversed => 'Dibalik',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $this === self::Posted && $target === self::Reversed;
    }
}
