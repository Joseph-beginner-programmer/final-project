<?php

namespace App\DTO\Sales;

use App\Enums\PaymentTerm;

class SalesOrderData
{
    /**
     * @param  list<array{productId: int, quantity: string, unitPrice: string}>  $lines
     * @param  list<array{description: string, amount: string}>  $charges
     */
    public function __construct(
        public readonly int $customerId,
        public readonly int $salespersonId,
        public readonly string $orderDate,
        public readonly ?string $expectedDeliveryDate,
        public readonly PaymentTerm $paymentTerms,
        public readonly array $lines,
        public readonly string $discountPercent = '0',
        public readonly array $charges = [],
        public readonly ?string $notes = null,
        public readonly ?string $deliveryAddress = null,
        public readonly ?string $contactPerson = null,
        public readonly ?string $contactPhone = null,
    ) {}
}
