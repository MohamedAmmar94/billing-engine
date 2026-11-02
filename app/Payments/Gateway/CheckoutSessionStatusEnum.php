<?php

declare(strict_types=1);

namespace App\Payments\Gateway;

enum CheckoutSessionStatusEnum: string
{
    case Open = 'open';
    case Complete = 'complete';
    case Expired = 'expired';
}
