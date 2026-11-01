<?php

declare (strict_types = 1);

namespace App\Billing\Exceptions;

use DomainException;

final class InvalidInvoiceException extends DomainException {
    public static function noItems(): self {
        return new self('An invoice needs at least one item.');
    }

    public static function invalidQuantity(int $quantity): self {
        return new self("Quantity must be at least 1, got {$quantity}.");
    }

    public static function inactiveProduct(string $sku): self {
        return new self("Product {$sku} is not active.");
    }

    public static function currencyMismatch(string $sku): self {
        return new self("Product {$sku} uses a different currency than the invoice.");
    }

    public static function nothingToBill(): self {
        return new self('Cannot open an invoice with nothing to bill.');
    }
}