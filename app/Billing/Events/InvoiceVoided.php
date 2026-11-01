<?php

declare(strict_types=1);

namespace App\Billing\Events;

use App\Billing\Models\Invoice;

final readonly class InvoiceVoided
{
    public function __construct(public Invoice $invoice) {}
}
