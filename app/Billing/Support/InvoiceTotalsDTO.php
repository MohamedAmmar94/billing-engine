<?php

declare(strict_types=1);

namespace App\Billing\Support;

use App\Shared\Money\Money;

final readonly class InvoiceTotalsDTO
{
    public function __construct(
        public Money $subtotal,
        public Money $tax,
        public Money $total,
    ) {}
}
