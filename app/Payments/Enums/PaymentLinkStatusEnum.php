<?php

declare(strict_types=1);

namespace App\Payments\Enums;

enum PaymentLinkStatusEnum: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Expired = 'expired';
    case Canceled = 'canceled';
    case Pending = 'pending';

    /** @return list<string> */
    public static function liveValues(): array
    {
        return [self::Pending->value, self::Open->value];
    }
}
