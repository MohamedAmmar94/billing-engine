<?php

declare(strict_types=1);

namespace App\Shared\Money;

use DomainException;

class CurrencyMismatchException extends DomainException
{
    /**
     * Create a new class instance.
     */
    public static function between(CurrencyEnum $a, CurrencyEnum $b): self
    {
        return new self("Currency mismatch: {$a->value} vs {$b->value}.");
    }
}
