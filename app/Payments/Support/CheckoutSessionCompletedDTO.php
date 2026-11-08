<?php

declare(strict_types=1);

namespace App\Payments\Support;

use Carbon\CarbonImmutable;

final readonly class CheckoutSessionCompletedDTO
{
    /** @param  array<string, mixed>  $raw  الـ session كاملة */
    public function __construct(
        public string $sessionId,
        public string $clientReferenceId,
        public string $paymentStatus,
        public ?string $paymentIntentId,
        public int $amountTotal,
        public string $currency,
        public CarbonImmutable $paidAt,
        public array $raw,
    ) {}
}
