<?php

namespace App\Enums;

enum SalesOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case PartiallyDelivered = 'partially_delivered';
    case FullyDelivered = 'fully_delivered';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Confirmed, self::Cancelled], true),
            self::Confirmed => in_array($target, [self::PartiallyDelivered, self::FullyDelivered, self::Cancelled], true),
            self::PartiallyDelivered => $target === self::FullyDelivered,
            self::FullyDelivered, self::Cancelled => false,
        };
    }

    public function isDeliverable(): bool
    {
        return in_array($this, [self::Confirmed, self::PartiallyDelivered], true);
    }

    public function label(): string
    {
        return __(match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Confirmed',
            self::PartiallyDelivered => 'Partially Delivered',
            self::FullyDelivered => 'Fully Delivered',
            self::Cancelled => 'Cancelled',
        });
    }
}
