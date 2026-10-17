<?php

namespace App\Actions\MasterData;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveSupplierAction
{
    /**
     * @param  array{supplier_name: string, contact_person: ?string, phone: ?string, email: ?string, address: ?string, is_active: bool}  $fields
     */
    public function handle(array $fields, ?Supplier $supplier = null): Supplier
    {
        $fields = array_map(fn ($value) => is_string($value) && blank($value) ? null : $value, $fields);

        if ($supplier) {
            $supplier->update($fields);

            return $supplier;
        }

        return DB::transaction(function () use ($fields) {
            $supplier = new Supplier($fields);
            $supplier->supplier_code = (string) Str::uuid();
            $supplier->save();

            $supplier->supplier_code = sprintf('SUP-%03d', $supplier->id);
            $supplier->save();

            return $supplier;
        });
    }
}
