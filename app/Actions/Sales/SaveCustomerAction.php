<?php

namespace App\Actions\Sales;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveCustomerAction
{
    /**
     * @param  array{customer_name: string, contact_person: ?string, phone: ?string, email: ?string, address: ?string}  $fields
     */
    public function handle(array $fields, ?Customer $customer = null): Customer
    {
        $fields = array_map(fn ($value) => filled($value) ? $value : null, $fields);

        if ($customer) {
            $customer->update($fields);

            return $customer;
        }

        return DB::transaction(function () use ($fields) {
            $customer = new Customer($fields);
            $customer->customer_code = (string) Str::uuid();
            $customer->save();

            $customer->customer_code = sprintf('CUS-%06d', $customer->id);
            $customer->save();

            return $customer;
        });
    }
}
