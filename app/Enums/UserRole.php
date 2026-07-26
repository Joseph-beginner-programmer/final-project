<?php

namespace App\Enums;

enum UserRole: string
{
    case Purchasing = 'purchasing';
    case Sales = 'sales';
    case Accounting = 'accounting';
    case Production = 'production';
    case Warehouse = 'warehouse';
    case SystemAdmin = 'system_admin';
    case Manager = 'manager';

    public function label(): string
    {
        return match($this) {
            self::Purchasing => 'Purchasing',
            self::Sales => 'Sales',
            self::Accounting => 'Accounting',
            self::Production => 'Production',
            self::Warehouse => 'Warehouse',
            self::SystemAdmin => 'System Admin',
            self::Manager => 'Manager',
        };
    }

    public function dashboardRoute(): string
    {
        return match($this) {
            self::Purchasing => 'purchasing.dashboard',
            self::Sales => 'sales.dashboard',
            self::Accounting => 'accounting.dashboard',
            self::Production => 'production.dashboard',
            self::Warehouse => 'warehouse.dashboard',
            self::SystemAdmin => 'admin.dashboard',
            self::Manager => 'executive.dashboard',
        };
    }

    public static function operational(): array
    {
        return [
            self::Purchasing,
            self::Sales,
            self::Accounting,
            self::Production,
            self::Warehouse
        ];
    }

}