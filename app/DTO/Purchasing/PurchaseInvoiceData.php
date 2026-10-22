<?php

namespace App\DTO\Purchasing;

class PurchaseInvoiceData
{
    /**
     * @param  array<int, string>  $unitPrices
     * @param  list<array{description: string, account_id: int, amount: string}>  $charges
     */
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly string $invoiceDate,
        public readonly string $dueDate,
        public readonly string $taxRate,
        public readonly array $unitPrices,
        public readonly string $capitalizedCharges,
        public readonly array $charges = [],
        public readonly ?string $notes = null,
    ) {}
}
