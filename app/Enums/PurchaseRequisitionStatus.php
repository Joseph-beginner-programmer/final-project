<?php

namespace App\Enums;

enum PurchaseRequisitionStatus: string
{
    case Submitted = 'submitted';
    case Processed = 'processed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __(match ($this) {
            self::Submitted => 'Submitted',
            self::Processed => 'Turned into PO',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        });
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Submitted => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20',
            self::Processed => 'bg-green-50 text-green-700 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
            self::Rejected => 'bg-red-50 text-red-700 border-red-200 dark:bg-red-500/10 dark:text-red-400 dark:border-red-500/20',
            self::Cancelled => 'bg-zinc-100 text-zinc-500 border-zinc-200 line-through dark:bg-white/5 dark:text-zinc-500 dark:border-white/10',
        };
    }
}
