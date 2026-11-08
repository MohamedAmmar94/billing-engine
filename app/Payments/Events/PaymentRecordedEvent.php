<?php

declare(strict_types=1);

namespace App\Payments\Events;

use App\Payments\Models\Payment;

final readonly class PaymentRecordedEvent
{
    public function __construct(public Payment $payment) {}
}
