<?php

namespace App\Enums; 

enum StockMovementType: string
{
    case PurchaseReceived = 'purchase_received';
    case PurchaseReturn = 'purchase_return';
    case SalesShipment = 'sales_shipment';
    case SalesReturn = 'sales_return';
    case ProductionOutput = 'production_output';
    case ProductionInput = 'production_input';
    case StockAdjustment = 'stock_adjustment';

}