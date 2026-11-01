<?php

declare(strict_types=1);

namespace App\Payments\Enums;

enum PaymentLinkStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Expired = 'expired';
    case Canceled = 'canceled';
    case Pending = 'pending';

    public function isLive(): bool
    {
        return $this === self::Pending || $this === self::Open;
    }
}
