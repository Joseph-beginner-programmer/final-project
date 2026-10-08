<?php

namespace App\Enums;

enum WorkOrderStatus: string
{
    case Draft = 'draft';
    case Released = 'released';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case CompletedShort = 'completed_short';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Released, self::Cancelled], true),
            self::Released => in_array($target, [self::InProgress, self::Cancelled], true),
            self::InProgress => in_array($target, [self::Completed, self::CompletedShort], true),
            self::Completed, self::CompletedShort, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::CompletedShort, self::Cancelled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Released => 'Released',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::CompletedShort => 'Completed Short',
            self::Cancelled => 'Cancelled',
        };
    }
}
