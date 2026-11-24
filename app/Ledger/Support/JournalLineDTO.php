<?php

declare(strict_types=1);

namespace App\Ledger\Support;

use App\Ledger\Enums\AccountCodeEnum;
use App\Ledger\Enums\NormalSideEnum;
use App\Ledger\Exceptions\LedgerException;
use App\Shared\Money\Money;

final readonly class JournalLineDTO
{
    private function __construct(
        public AccountCodeEnum $account,
        public NormalSideEnum $side, // مدين أو دائن: بنستخدم نفس الـ enum كاتجاه
        public Money $amount,
    ) {}

    public static function debit(AccountCodeEnum $account, Money $amount): self
    {
        return new self($account, NormalSideEnum::Debit, self::positive($amount));
    }

    public static function credit(AccountCodeEnum $account, Money $amount): self
    {
        return new self($account, NormalSideEnum::Credit, self::positive($amount));
    }

    private static function positive(Money $amount): Money
    {
        if (! $amount->isPositive()) {
            throw LedgerException::nonPositiveLine();
        }

        return $amount;
    }
}
