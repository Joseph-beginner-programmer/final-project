<?php

namespace App\Enums; 

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case PartiallyReceived = 'partially_received';
    case FullyReceived = 'fully_received';
    case Cancelled = 'cancelled';
    // case Closed = 'closed';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::PendingApproval, self::Cancelled], true),
            self::PendingApproval => in_array($target, [self::Approved, self::Draft, self::Rejected, self::Cancelled], true),
            self::Approved => in_array($target, [self::PartiallyReceived, self::FullyReceived, self::Cancelled], true),
            self::Rejected => $target === self::Draft,
            self::PartiallyReceived => in_array($target, [self::FullyReceived], true),
            self::FullyReceived, self::Cancelled => false, 
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::FullyReceived, self::Cancelled], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::PartiallyReceived => 'Partially Received',
            self::FullyReceived => 'Fully Received',
            self::Cancelled => 'Cancelled',
        };
    }
}