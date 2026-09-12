<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Guarded(['id'])]
class PurchaseOrderReceiptBatch extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime'
        ];
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseOrderReceipts(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceipt::class);
    }

    public function purchaseOrderReceiptAttachments(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceiptAttachment::class);
    }
}
