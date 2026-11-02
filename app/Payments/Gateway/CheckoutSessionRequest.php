<?php

declare(strict_types=1);

namespace App\Payments\Gateway;

use App\Shared\Money\Money;
use Carbon\CarbonImmutable;

final readonly class CheckoutSessionRequest
{
    public function __construct(
        public Money $amount,
        public string $description,
        public string $clientReferenceId,
        public string $idempotencyKey,
        public CarbonImmutable $expiresAt,
        public string $successUrl,
        public string $cancelUrl,
    ) {}
}
