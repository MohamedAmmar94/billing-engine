<?php

declare(strict_types=1);

namespace App\Billing\Enums;

enum InvoiceStatusEnum: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Draft => [self::Open],
            self::Open => [self::Paid, self::Void],
            self::Paid, self::Void => [],
        }, true);
    }
}
