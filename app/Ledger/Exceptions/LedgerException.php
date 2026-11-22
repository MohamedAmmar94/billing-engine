<?php

declare(strict_types=1);

namespace App\Ledger\Exceptions;

use DomainException;

class LedgerException extends DomainException
{
    public static function tooFewLines(): self
    {
        return new self('A journal entry needs at least two lines.');
    }

    public static function nonPositiveLine(): self
    {
        return new self('A journal line amount must be greater than zero.');
    }

    public static function lineCurrencyMismatch(string $accountCode, string $entryCurrency): self
    {
        return new self("A line for account {$accountCode} is not in the entry currency {$entryCurrency}.");
    }

    public static function unknownAccount(string $code): self
    {
        return new self("Unknown ledger account {$code}.");
    }

    public static function accountNotPostable(string $code): self
    {
        return new self("Account {$code} is a group account and cannot receive entries.");
    }

    public static function accountCurrencyMismatch(string $code, string $entryCurrency): self
    {
        return new self("Account {$code} does not use the entry currency {$entryCurrency}.");
    }

    public static function missingBalanceRow(): self
    {
        return new self('An account has no balance row. Run ChartOfAccountsSeeder.');
    }

    public static function alreadyPosted(string $sourceType, int $sourceId, string $event): self
    {
        return new self("An entry for {$sourceType} {$sourceId} ({$event}) was already posted.");
    }

    public static function alreadyReversed(int $entryId): self
    {
        return new self("Journal entry {$entryId} was already reversed.");
    }
}
