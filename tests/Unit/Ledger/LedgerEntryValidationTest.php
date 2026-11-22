<?php

declare(strict_types=1);

use App\Ledger\Enums\AccountCodeEnum;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\Exceptions\UnbalancedEntryException;
use App\Ledger\Services\LedgerService;
use App\Ledger\Support\JournalLineDTO;
use App\Shared\Money\CurrencyEnum;
use App\Shared\Money\Money;

it('rejects an unbalanced entry before touching the database', function () {
    $entry = ledgerEntry([
        JournalLineDTO::debit(AccountCodeEnum::AccountsReceivable, ledgerUsd(1000)),
        JournalLineDTO::credit(AccountCodeEnum::SalesRevenue, ledgerUsd(900)),
    ]);

    expect(fn () => (new LedgerService)->post($entry))
        ->toThrow(UnbalancedEntryException::class, 'debit 10.00 <> credit 9.00 (USD)');
});

it('compares the totals of all lines, not line by line', function () {
    $entry = ledgerEntry([
        JournalLineDTO::debit(AccountCodeEnum::AccountsReceivable, ledgerUsd(1000)),
        JournalLineDTO::credit(AccountCodeEnum::SalesRevenue, ledgerUsd(400)),
        JournalLineDTO::credit(AccountCodeEnum::TaxPayable, ledgerUsd(599)),
    ]);

    expect(fn () => (new LedgerService)->post($entry))
        ->toThrow(UnbalancedEntryException::class, 'debit 10.00 <> credit 9.99');
});

it('rejects an entry with fewer than two lines', function () {
    $entry = ledgerEntry([JournalLineDTO::debit(AccountCodeEnum::AccountsReceivable, ledgerUsd(1000))]);

    expect(fn () => (new LedgerService)->post($entry))
        ->toThrow(LedgerException::class, 'at least two lines');
});

it('rejects lines that are not strictly positive', function (int $cents) {
    expect(fn () => JournalLineDTO::debit(AccountCodeEnum::AccountsReceivable, Money::of($cents, CurrencyEnum::USD)))
        ->toThrow(LedgerException::class, 'greater than zero');
})->with([0, -5]);

it('rejects a line in another currency than the entry', function () {
    $entry = ledgerEntry([
        JournalLineDTO::debit(AccountCodeEnum::AccountsReceivable, Money::of(1000, CurrencyEnum::EGP)),
        JournalLineDTO::credit(AccountCodeEnum::SalesRevenue, Money::of(1000, CurrencyEnum::EGP)),
    ]);

    expect(fn () => (new LedgerService)->post($entry))
        ->toThrow(LedgerException::class, 'not in the entry currency USD');
});
