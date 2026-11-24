<?php

declare(strict_types=1);

namespace App\Ledger\Support;

use App\Shared\Money\CurrencyEnum;
use Carbon\CarbonImmutable;

final readonly class JournalEntryDTO
{
    /** @param  list<JournalLineDTO>  $lines */
    public function __construct(
        public string $description,
        public CarbonImmutable $postedAt,
        public CurrencyEnum $currency,
        public string $sourceType,
        public int $sourceId,
        public string $event,
        public array $lines,
        public ?int $reversesEntryId = null,
    ) {}
}
