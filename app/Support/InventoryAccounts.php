<?php

namespace App\Support;

use App\Enums\AccountSystemRole;
use App\Enums\ProductType;

class InventoryAccounts
{
    /**
     * @param  iterable<array{type: ProductType, amount: string}>  $amounts
     * @return array<string, array{role: AccountSystemRole, amount: string}>
     */
    public static function group(iterable $amounts): array
    {
        $grouped = [];
        foreach ($amounts as $row) {
            $role = $row['type']->inventoryRole();
            $grouped[$role->value] ??= ['role' => $role, 'amount' => '0'];
            $grouped[$role->value]['amount'] = bcadd($grouped[$role->value]['amount'], (string) $row['amount'], 2);
        }

        return array_filter($grouped, fn (array $row) => bccomp($row['amount'], '0', 2) !== 0);
    }
}
