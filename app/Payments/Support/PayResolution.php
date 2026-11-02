<?php

declare(strict_types=1);

namespace App\Payments\Support;

final readonly class PayResolution
{
    private function __construct(
        public PayOutcome $outcome,
        public ?string $url = null,
    ) {}

    public static function redirect(string $url): self
    {
        return new self(PayOutcome::Redirect, $url);
    }

    public static function alreadyPaid(): self
    {
        return new self(PayOutcome::AlreadyPaid);
    }

    public static function processing(): self
    {
        return new self(PayOutcome::Processing);
    }

    public static function tryAgain(): self
    {
        return new self(PayOutcome::TryAgain);
    }

    public static function notPayable(): self
    {
        return new self(PayOutcome::NotPayable);
    }

    public static function unavailable(): self
    {
        return new self(PayOutcome::Unavailable);
    }
}
