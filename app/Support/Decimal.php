<?php

namespace App\Support;

class Decimal
{
    public static function plain(string|int|float|null $value): string
    {
        $value = (string) ($value ?? '');

        if ($value === '' || ! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
