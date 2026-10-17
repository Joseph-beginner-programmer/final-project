<?php

namespace App\Enums;

enum OverheadCategory: string
{
    case Electricity = 'electricity';
    case MachineDepreciation = 'machine_depreciation';
    case MoldDepreciation = 'mold_depreciation';
    case Maintenance = 'maintenance';
    case Other = 'other';

    public function label(): string
    {
        return __(match ($this) {
            self::Electricity => 'Electricity',
            self::MachineDepreciation => 'Machine depreciation',
            self::MoldDepreciation => 'Mold depreciation',
            self::Maintenance => 'Maintenance',
            self::Other => 'Other overhead',
        });
    }

    public function hint(): string
    {
        return __(match ($this) {
            self::Electricity => 'This work center\'s share of the factory electricity bill',
            self::MachineDepreciation => 'Monthly depreciation of the machines in this work center',
            self::MoldDepreciation => 'Monthly depreciation of the molds used here',
            self::Maintenance => 'Spare parts and technician visits',
            self::Other => 'Any other factory overhead',
        });
    }
}
