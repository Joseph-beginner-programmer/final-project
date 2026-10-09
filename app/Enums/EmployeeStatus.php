<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return __(match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        });
    }
}
