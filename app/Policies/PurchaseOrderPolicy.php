<?php

namespace App\Policies;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    public function view(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchasing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchasing.create');
    }

    public function submit(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchasing.create')
            && $po->created_by === $user->id
            && $po->status === PurchaseOrderStatus::Draft;
    }

    public function update(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchasing.create')
            && $po->status === PurchaseOrderStatus::Draft;
    }

    public function approve(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchasing.approve')
            && $po->created_by !== $user->id
            && $po->status === PurchaseOrderStatus::PendingApproval;
    }

    public function reject(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchasing.approve')
            && $po->created_by !== $user->id
            && $po->status === PurchaseOrderStatus::PendingApproval;
    }

    public function receive(User $user, PurchaseOrder $po): bool
    {
        return $user->can('warehouse.receive')
            && in_array($po->status, [
                PurchaseOrderStatus::Approved,
                PurchaseOrderStatus::PartiallyReceived,
            ], true);
    }

    public function cancel(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchasing.cancel')
            && in_array($po->status, [
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::PendingApproval,
                PurchaseOrderStatus::Approved,
            ], true);
    }

    public function open(User $user, PurchaseOrder $po): bool 
    {
        return $user->can('purchasing.open')
            && $po->status === PurchaseOrderStatus::Rejected;   
    }

    public function close(User $user, PurchaseOrder $po): bool
    {
        return $user->can('purchasing.close')
            && $po->status === PurchaseOrderStatus::PartiallyReceived;
    }
}