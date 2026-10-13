<?php

namespace App\Models;

use App\Enums\PaymentTerm;
use App\Enums\SalesOrderStatus;
use App\Exceptions\InvalidSalesOrderStatusTransitionException;
use App\Exceptions\SalesOrderNotEditableException;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property SalesOrderStatus $status
 */
#[Guarded(['id', 'so_number', 'status', 'total_amount', 'discount_amount', 'confirmed_by', 'confirmed_at', 'cancelled_by', 'cancelled_at', 'cancel_reason'])]
class SalesOrder extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'payment_terms' => PaymentTerm::class,
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(SalesOrderCharge::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function itemsSubtotal(): string
    {
        return $this->items->reduce(fn (string $carry, SalesOrderItem $item) => bcadd($carry, (string) $item->subtotal, 2), '0');
    }

    public function chargesTotal(): string
    {
        return $this->charges->reduce(fn (string $carry, SalesOrderCharge $charge) => bcadd($carry, (string) $charge->amount, 2), '0');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SalesDelivery::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function transitionTo(SalesOrderStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new InvalidSalesOrderStatusTransitionException($this->status, $target, $this->so_number);
        }

        $this->status = $target;
        $this->save();
    }

    public function ensureIsDraft(): void
    {
        if ($this->status !== SalesOrderStatus::Draft) {
            throw new SalesOrderNotEditableException($this);
        }
    }
    
    public function syncDeliveryStatus(): void
    {
        $allDone = $this->items()->get()->every(fn (SalesOrderItem $item) => $item->isFullyDelivered());
        $target = $allDone ? SalesOrderStatus::FullyDelivered : SalesOrderStatus::PartiallyDelivered;

        if ($this->status !== $target) {
            $this->transitionTo($target);
        }
    }

    public function hasDeliveries(): bool
    {
        return $this->items()->where('quantity_delivered', '>', 0)->exists();
    }
}
