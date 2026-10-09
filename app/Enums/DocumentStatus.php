<?php

namespace App\Enums;

/**
 * The ERD's `document_status` (draft → posted, or cancelled), shared by documents that move stock:
 * material issues now; purchase returns and sales deliveries later.
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Posted, self::Cancelled], true),
            self::Posted, self::Cancelled => false,
        };
    }

    public function label(): string
    {
        return __(match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Cancelled => 'Cancelled',
        });
    }
}
