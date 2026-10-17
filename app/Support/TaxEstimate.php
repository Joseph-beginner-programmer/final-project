<?php

namespace App\Support;

class TaxEstimate
{
    /**
     * @return array{rate: string, tax: string, total: string}
     */
    public static function for(string $amount): array
    {
        $rate = (string) config('tax.estimate_rate');
        $tax = bcdiv(bcmul($amount, $rate, 4), '100', 2);

        return [
            'rate' => $rate,
            'tax' => $tax,
            'total' => bcadd($amount, $tax, 2),
        ];
    }
}
