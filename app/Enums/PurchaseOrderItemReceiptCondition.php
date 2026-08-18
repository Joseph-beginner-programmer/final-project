<?php

namespace App\Enums;

enum PurchaseOrderItemReceiptCondition: string 
{
    case ReceivedAsExpected = 'received_as_expected';

    case ReceivedMoreThanOrdered = 'received_more_than_ordered';

    case ReceivedLessThanOrdered = 'received_less_than_ordered';

    case DamagedGoods = 'damaged_goods';

    public function label(): string 
    {
        return match($this) {
            self::ReceivedAsExpected => 'Received As Expected',
            self::ReceivedMoreThanOrdered => 'Received More Than Ordered',
            self::ReceivedLessThanOrdered => 'Received Less Than Ordered',
            self::DamagedGoods => 'Damaged Goods',
        };
    }
}