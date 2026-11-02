<?php

declare(strict_types=1);

namespace App\Shared\Money;

enum CurrencyEnum: string
{
    case EGP = 'EGP';
    case USD = 'USD';
    case EUR = 'EUR';
    case JPY = 'JPY';

    public function exponent(): int
    {
        return match ($this) {
            self::JPY => 0,
            default => 2,
        };
    }
}
