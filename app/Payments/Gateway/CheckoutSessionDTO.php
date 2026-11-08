<?php

declare(strict_types=1);

namespace App\Payments\Gateway;

use Carbon\CarbonImmutable;

final readonly class CheckoutSessionDTO
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $url,
        public CheckoutSessionStatusEnum $status,
        public CarbonImmutable $expiresAt,
        public array $raw = [],

    ) {}
}
