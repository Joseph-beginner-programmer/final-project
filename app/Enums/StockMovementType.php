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
    case MaterialReturn = 'material_return';

    public function label(): string
    {
        return __(match ($this) {
            self::PurchaseReceived => 'Purchase receipt',
            self::PurchaseReturn => 'Purchase return',
            self::SalesShipment => 'Sales delivery',
            self::SalesReturn => 'Sales return',
            self::ProductionOutput => 'Production output',
            self::ProductionInput => 'Issued to production',
            self::StockAdjustment => 'Stock adjustment',
            self::MaterialReturn => 'Material return',
        });
    }
}