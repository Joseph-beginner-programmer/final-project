<?php

namespace App\Support\Reports;

use App\Enums\ProductType;
use Carbon\Carbon;

class ReportPeriod
{
    public static function normalize(?string $period): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $period) === 1 ? $period : now()->format('Y-m');
    }

    public static function start(string $period): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', self::normalize($period).'-01')->startOfDay();
    }

    public static function end(string $period): Carbon
    {
        return self::start($period)->endOfMonth();
    }

    /**
     * @param  list<ProductType>  $allowed
     */
    public static function type(?string $value, array $allowed): ?ProductType
    {
        $type = ProductType::tryFrom((string) $value);

        return in_array($type, $allowed, true) ? $type : null;
    }
}
