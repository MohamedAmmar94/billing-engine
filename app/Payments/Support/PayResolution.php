<?php

declare(strict_types=1);

namespace App\Payments\Support;

final readonly class PayResolution
{
    private function __construct(
        public PayOutcomeEnum $outcome,
        public ?string $url = null,
    ) {}

    public static function redirect(string $url): self
    {
        return new self(PayOutcomeEnum::Redirect, $url);
    }

    public static function alreadyPaid(): self
    {
        return new self(PayOutcomeEnum::AlreadyPaid);
    }

    public static function processing(): self
    {
        return new self(PayOutcomeEnum::Processing);
    }

    public static function tryAgain(): self
    {
        return new self(PayOutcomeEnum::TryAgain);
    }

    public static function notPayable(): self
    {
        return new self(PayOutcomeEnum::NotPayable);
    }

    public static function unavailable(): self
    {
        return new self(PayOutcomeEnum::Unavailable);
    }
}
