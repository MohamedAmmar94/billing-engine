<?php

declare(strict_types=1);

namespace App\Billing\Exceptions;

use App\Billing\Enums\InvoiceStatus;
use DomainException;

final class InvoiceStateException extends DomainException
{
    public static function cannot(string $action, InvoiceStatus $from): self
    {
        return new self("Cannot {$action} an invoice that is {$from->value}.");
    }
}
