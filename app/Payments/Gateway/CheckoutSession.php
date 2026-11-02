<?php

declare(strict_types=1);

namespace App\Payments\Gateway;

use Carbon\CarbonImmutable;

final readonly class CheckoutSession
{
    public function __construct(
        public string $id,
        public ?string $url,
        public CheckoutSessionStatus $status,
        public CarbonImmutable $expiresAt,
    ) {}
}
