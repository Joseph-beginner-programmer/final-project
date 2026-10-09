<?php

namespace App\Enums;

enum WorkOrderStatus: string
{
    case Draft = 'draft';
    case Released = 'released';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Released, self::Cancelled], true),
            self::Released => in_array($target, [self::InProgress, self::Cancelled], true),
            // posting the production result completes the WO — under target is just a smaller batch
            self::InProgress => $target === self::Completed,
            self::Completed, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function label(): string
    {
        return __(match ($this) {
            self::Draft => 'Draft',
            self::Released => 'Released',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        });
    }
}
