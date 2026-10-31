<?php

declare(strict_types=1);

namespace App\Shared\Money;

use DomainException;

class CurrencyMismatchException extends DomainException
{
    /**
     * Create a new class instance.
     */
    public static function between(Currency $a, Currency $b): self
    {
        return new self("Currency mismatch: {$a->value} vs {$b->value}.");
    }
}
